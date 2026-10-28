<?php

use App\Enums\ActivityType;
use App\Concerns\ConfirmsAutosave;
use App\Concerns\EditsMarkdown;
use App\Concerns\ListensForRealtime;
use App\Enums\AutomationTrigger;
use App\Enums\CustomFieldType;
use App\Enums\RepeatMode;
use App\Enums\RepeatUnit;
use App\Emoji;
use App\Models\Attachment;
use App\Models\Automation;
use App\Models\Comment;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\MarkdownService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use ConfirmsAutosave;
    use EditsMarkdown;
    use ListensForRealtime;
    use WithFileUploads;

    /** How many tasks the pickers for parent task and dependencies list at most besides the chosen ones. */
    private const PICKER_LIMIT = 50;

    /** How many comments and changes the activity feed shows at first and adds per "show earlier". */
    private const FEED_PAGE = 50;

    /** The columns of the people in the pickers: what the name, the avatar and the away note need. */
    private const USER_COLUMNS = ['id', 'name', 'avatar_updated_at', 'timezone', 'deactivated_at', 'absent_from', 'absent_until'];

    /**
     * The pickers that load their choices on demand (see loadPicker), with the islands of the page that hold them.
     *
     * @var array<string, list<string>>
     */
    private const PICKERS = [
        'people' => ['assignee-choices', 'collaborator-choices'],
        'tags' => ['tag-choices'],
        'parent' => ['parent-choices'],
        'blockers' => ['blocker-choices'],
        'blocking' => ['blocking-choices'],
    ];

    public Task $task;

    /** Shown in a flyout over a list or board instead of as a page of its own. */
    public bool $panel = false;

    public string $title = '';

    public string $description = '';

    public string $statusId = '';

    public string $assigneeId = '';

    public string $dueDate = '';

    public string $startDate = '';

    public string $repeatUnit = '';

    public string $repeatInterval = '1';

    public string $repeatMode = 'schedule';

    public string $repeatUntil = '';

    /** @var array<int|string, string|null> */
    public array $fieldValues = [];

    public string $comment = '';

    public ?int $editingCommentId = null;

    public string $editingBody = '';

    /** @var list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null Image pasted or dropped into a text. */
    public $inlineUpload = null;

    /** @var list<string> */
    public array $tagIds = [];

    public string $newTag = '';

    public bool $notificationsOn = true;

    public string $parentId = '';

    /** What was typed into the search of the pickers for parent task and dependencies. */
    public string $parentSearch = '';

    public string $blockerSearch = '';

    public string $blockingSearch = '';

    /** @var array<int|string, string> */
    public array $newSubtaskTitles = [];

    /** @var array<int|string, string> */
    public array $sectionTitles = [];

    /** @var list<string> */
    public array $collaboratorIds = [];

    /** @var list<string> */
    public array $blockerIds = [];

    /** @var list<string> */
    public array $blockingIds = [];

    /** Somebody else saved this task while it is open here; the form keeps what was typed until it is reloaded. */
    public bool $changedElsewhere = false;

    /**
     * The pickers whose full list of choices is part of the page (see loadPicker). Until then a picker holds only what
     * is chosen, so opening the flyout does not send the people, tags and tasks of the whole project.
     *
     * @var list<string>
     */
    #[Locked]
    public array $loadedPickers = [];

    /** How many of the latest activity entries are shown. */
    #[Locked]
    public int $feedLimit = self::FEED_PAGE;

    /** How many of the latest comments are shown. */
    #[Locked]
    public int $commentLimit = self::FEED_PAGE;

    /**
     * The choices of a picker: the first time somebody focuses or uses the field (see lazyPicker in app.js). Fields that
     * share their choices (assignee and collaborators) share the name.
     *
     * Only the islands that hold the choices are sent (PICKERS), not the page: rendering it whole again (comments, activity,
     * subtasks) cost 23 to 26 queries and 640 to 840 KB of HTML for a task with 62 comments; the islands take 5 to 8 queries
     * and 26 to 69 KB, almost all of it the choices themselves. The islands render with the page as before (always), so
     * what is chosen shows after every save; they carry variant="listbox" because an island sent alone has no flux:select
     * around it to take the variant from.
     */
    public function loadPicker(string $picker): void
    {
        $this->skipRender();

        if (! array_key_exists($picker, self::PICKERS) || $this->pickerLoaded($picker)) {
            return;
        }

        $this->loadedPickers[] = $picker;

        foreach (self::PICKERS[$picker] as $island) {
            $this->renderIsland($island);
        }
    }

    private function pickerLoaded(string $picker): bool
    {
        return in_array($picker, $this->loadedPickers, true);
    }

    public function hydrate(): void
    {
        Gate::authorize('view', $this->task->project);
    }

    /**
     * The "e" key: done or open again, without touching what is being edited in the form (unless a rule changed the task: then the
     * page asks whether to reload it).
     */
    #[On('shortcut-toggle-done')]
    public function toggleDoneByShortcut(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $before = $this->storedStateRulesChange(AutomationTrigger::StatusChanged);

        $this->task->toggleDone();
        $this->statusId = (string) $this->task->status_id;
        $this->fillRepeatRule();
        $this->announceChange();

        $this->askToReloadWhenRulesChangedTask($before, AutomationTrigger::StatusChanged, ['status']);
    }

    /**
     * A rule that fires on "done" or on a new assignee may have handed the task on, moved its dates or set tags and fields: the
     * form still shows the old values, and saving it would undo the rule. The page decides what happens, as only it knows
     * whether something was typed (task-form-outdated).
     *
     * @param  array<string, mixed>|null  $before  the state before the shortcut, null when no rule could run
     * @param  AutomationTrigger  $trigger  the trigger the shortcut fires, the only rules that can run
     * @param  list<string>  $expected  the parts of the state the shortcut itself changes
     * @param  int|null  $expectedAssigneeId  who must hold the task afterwards, if the shortcut hands it over
     */
    private function askToReloadWhenRulesChangedTask(?array $before, AutomationTrigger $trigger, array $expected, ?int $expectedAssigneeId = null): void
    {
        if ($before === null) {
            return;
        }

        $after = $this->storedStateRulesChange($trigger);

        if (Arr::except($after, $expected) !== Arr::except($before, $expected) || ($expectedAssigneeId !== null && (int) $after['assignee'] !== $expectedAssigneeId)) {
            $this->dispatch('task-form-outdated', question: __('A rule has just changed this task. Reload the form? Your unsaved input is lost. If you do not reload and save later, you overwrite what the rule changed.'));
        }
    }

    /**
     * What a rule can change on this task and the form shows, as stored now.
     *
     * @return array<string, mixed>|null null when the project has no enabled rule
     */
    private function storedStateRulesChange(AutomationTrigger $trigger): ?array
    {
        if (! Automation::query()->enabled()->where('project_id', $this->task->project_id)->where('trigger', $trigger)->exists()) {
            return null;
        }

        $task = Task::query()->with(['tags:id', 'collaborators:id', 'fieldValues'])->findOrFail($this->task->getKey());

        return [
            'status' => $task->status_id,
            'assignee' => $task->assignee_id,
            'due' => $task->due_date?->toDateString(),
            'start' => $task->start_date?->toDateString(),
            'tags' => $task->tags->pluck('id')->sort()->values()->all(),
            'collaborators' => $task->collaborators->pluck('id')->sort()->values()->all(),
            'fields' => $task->fieldValues->mapWithKeys(fn ($value) => [$value->custom_field_id => (string) ($value->option_id ?? $value->value)])->sortKeys()->all(),
        ];
    }

    /**
     * The "m" key: the task goes to the person at the keyboard, who then is no collaborator, as with the assignee menu of
     * the lists. Like "e" it leaves the rest of the form alone.
     */
    #[On('shortcut-assign-me')]
    public function assignToMeByShortcut(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if (! $this->users->contains('id', auth()->id())) {
            Flux::toast(variant: 'warning', text: __('You cannot be assigned to tasks of this project.'));

            return;
        }

        $before = $this->storedStateRulesChange(AutomationTrigger::AssigneeChanged);

        $this->task->assignTo(auth()->user());
        $this->assigneeId = (string) auth()->id();
        $this->collaboratorIds = $this->storedCollaboratorIds();
        $this->announceChange();

        $this->askToReloadWhenRulesChangedTask($before, AutomationTrigger::AssigneeChanged, ['assignee', 'collaborators'], (int) auth()->id());

        Flux::toast(variant: 'success', text: __('Assigned to you.'));
    }

    /**
     * React to the task or to one of its comments; the same emoji again takes the reaction back.
     */
    public function react(string $target, int $id, string $emoji): void
    {
        $this->authorizeEdit();

        $emoji = Emoji::normalize($emoji);

        if ($emoji === null) {
            Flux::toast(variant: 'danger', text: __('That is not an emoji.'));

            return;
        }

        $reactable = match ($target) {
            'task' => $this->task,
            'comment' => $this->task->comments()->findOrFail($id),
            default => abort(404),
        };

        $reactable->toggleReaction(auth()->user(), $emoji);

        $this->task->load('reactions.user');
        $this->forgetComments();
    }

    #[Computed]
    public function canEdit(): bool
    {
        return Gate::allows('edit', $this->task->project);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage', $this->task->project);
    }

    private function authorizeEdit(): void
    {
        Gate::authorize('edit', $this->task->project);
    }

    public function mount(): void
    {
        Gate::authorize('view', $this->task->project);

        // Coming here from a push, a mail or the inbox: what the inbox reported about this task has been seen (in the flyout the
        // flyout itself says so, see task-flyout: stepping through tasks with the keyboard does not mean reading them)
        if (! $this->panel && auth()->user()->markTaskNotificationsRead($this->task->id) > 0) {
            $this->dispatch('inbox-changed');
        }

        $this->fillForm();
        $this->notificationsOn = ! $this->task->isMutedBy(auth()->user());
        $this->sectionTitles = $this->subtreeTasks->where('is_section', true)->pluck('title', 'id')->all();
    }

    /**
     * The form as the task is stored.
     */
    private function fillForm(): void
    {
        $this->title = $this->task->title;
        $this->description = $this->task->description ?? '';
        $this->statusId = (string) $this->task->status_id;
        $this->assigneeId = (string) ($this->task->assignee_id ?? '');
        $this->dueDate = $this->task->due_date?->format('Y-m-d') ?? '';
        $this->startDate = $this->task->start_date?->format('Y-m-d') ?? '';
        $this->fillRepeatRule();
        $this->fieldValues = $this->storedFieldValues();
        $this->parentId = (string) ($this->task->parent_id ?? '');
        $this->tagIds = $this->task->tags->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->collaboratorIds = $this->storedCollaboratorIds();
        $this->blockerIds = $this->task->blockers()->pluck('tasks.id')->map(fn ($id) => (string) $id)->all();
        $this->blockingIds = $this->task->blocking()->pluck('tasks.id')->map(fn ($id) => (string) $id)->all();
    }

    public function updatedNotificationsOn(bool $value): void
    {
        $this->task->setMutedBy(auth()->user(), ! $value);
        $this->confirmSaved();
    }

    #[Computed]
    public function customFields(): Collection
    {
        return $this->task->project->customFields()->with('options')->get();
    }

    /**
     * Validation rules for the custom field values of this project.
     *
     * @return array<string, list<mixed>>
     */
    private function fieldRules(): array
    {
        return $this->customFields->mapWithKeys(fn (CustomField $field) => ["fieldValues.{$field->id}" => match ($field->type) {
            CustomFieldType::Select => ['nullable', Rule::in($field->options->pluck('id')->map(fn ($id) => (string) $id)->all())],
            CustomFieldType::Text => ['nullable', 'string', 'max:500'],
            CustomFieldType::Number => ['nullable', 'numeric'],
            CustomFieldType::Date => ['nullable', 'date_format:Y-m-d'],
        }])->all();
    }

    /**
     * The tags the task ends up with. Only what the form changed counts: tags it removed go, tags it added come, and the
     * rest stays as it is now, so a tag an automation added during the save is kept.
     *
     * @param  list<int>  $before  the tags when saving began
     * @param  list<int>  $fromForm  the tags in the form
     * @param  list<int>  $now  the tags the task has now
     * @return list<int>
     */
    private function tagsToKeep(array $before, array $fromForm, array $now): array
    {
        $removed = array_diff($before, $fromForm);
        $added = array_diff($fromForm, $before);

        return array_values(array_unique([...array_diff($now, $removed), ...$added]));
    }

    /**
     * Writes the fields the form changed; the others stay as they are (an automation may have set them meanwhile).
     *
     * @param  array<int|string, string|null>  $input
     * @param  array<int, string>  $before  The stored values when saving began.
     */
    private function saveFieldValues(array $input, array $before): void
    {
        foreach ($this->customFields as $field) {
            $new = trim((string) ($input[$field->id] ?? ''));

            if ($new === ($before[$field->id] ?? '')) {
                continue;
            }

            $this->task->setFieldValue($field, match (true) {
                $new === '' => null,
                $field->type === CustomFieldType::Select => ['option_id' => (int) $new, 'value' => null],
                default => ['option_id' => null, 'value' => $new],
            });
        }
    }

    /**
     * All descendants of this task, one query per level of the tree (not the whole project).
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function subtreeTasks(): Collection
    {
        $tasks = new Collection;
        $frontier = [$this->task->getKey()];
        $statuses = $this->task->project->statuses->keyBy('id');

        while ($frontier !== []) {
            $level = new Collection;

            foreach (array_chunk($frontier, 500) as $parents) {
                $level = $level->concat(
                    $this->task->project->tasks()->whereIn('parent_id', $parents)->orderBy('position')->orderBy('id')->get()
                );
            }

            // The statuses of the project are loaded anyway: no query per level for them
            $level->each(fn (Task $subtask) => $subtask->setRelation('status', $statuses->get($subtask->status_id)));
            $tasks = $tasks->concat($level);
            $frontier = $level->pluck('id')->all();
        }

        return $tasks->load('assignee');
    }

    #[Computed]
    public function childrenMap(): SupportCollection
    {
        return $this->subtreeTasks->groupBy(fn ($task) => $task->parent_id ?? 0);
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function descendantIds(): array
    {
        return $this->idsBelow($this->task->getKey());
    }

    /**
     * Ids of everything below the given task of this subtree, from the already loaded tasks.
     *
     * @return list<int>
     */
    private function idsBelow(int $taskId): array
    {
        $ids = [];
        $queue = [$taskId];

        while ($queue !== []) {
            foreach ($this->childrenMap->get(array_shift($queue), []) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    /**
     * @return list<Task>
     */
    #[Computed]
    public function ancestors(): array
    {
        $chain = [];
        $current = $this->task->parent_id === null ? null : $this->task->project->tasks()->find($this->task->parent_id);

        while ($current !== null) {
            array_unshift($chain, $current);
            $current = $current->parent_id === null ? null : $this->task->project->tasks()->find($current->parent_id);
        }

        return $chain;
    }

    /**
     * @return array{done: int, total: int}|null
     */
    #[Computed]
    public function progress(): ?array
    {
        $subtasks = $this->subtreeTasks->where('is_section', false);

        if ($subtasks->isEmpty()) {
            return null;
        }

        return ['done' => $subtasks->filter(fn (Task $subtask) => $subtask->isDone())->count(), 'total' => $subtasks->count()];
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function sectionIds(): array
    {
        return $this->subtreeTasks->where('is_section', true)->pluck('id')->all();
    }

    /**
     * The tasks the pickers for parent task and dependencies hold at the start: the chosen ones, from one query.
     *
     * @return SupportCollection<int, Task>
     */
    #[Computed]
    public function chosenTasks(): SupportCollection
    {
        $ids = array_values(array_unique([...($this->parentId === '' ? [] : [$this->parentId]), ...$this->blockerIds, ...$this->blockingIds]));

        return $ids === [] ? collect() : $this->task->project->tasks()->whereKey($ids)->get(['id', 'title', 'is_section'])->keyBy('id');
    }

    /**
     * What a picker for parent task or dependencies offers: what is already chosen and, once the picker is loaded, the
     * first matches of the search, so a project with thousands of tasks does not send them all to the browser.
     *
     * @param  list<int|string>  $selectedIds
     * @param  list<int>  $excludedIds
     * @return Collection<int, Task>
     */
    private function pickerTasks(string $picker, string $search, array $selectedIds, array $excludedIds, bool $withoutHeadings = false): Collection
    {
        $selectedIds = array_map(strval(...), $selectedIds);
        $chosen = $this->chosenTasks
            ->filter(fn (Task $task) => in_array((string) $task->id, $selectedIds, true)
                && ! in_array($task->id, $excludedIds, true)
                && ! ($withoutHeadings && $task->is_section))
            ->values();

        if (! $this->pickerLoaded($picker)) {
            return new Collection($chosen->all());
        }

        $search = trim($search);

        $matches = $this->task->project->tasks()
            ->whereNotIn('id', $excludedIds)
            ->when($withoutHeadings, fn ($query) => $query->withoutSections())
            ->when($search !== '', fn ($query) => $query->whereLike('title', '%'.$search.'%'))
            ->orderBy('title')
            ->limit(self::PICKER_LIMIT)
            ->get(['id', 'title']);

        return (new Collection($chosen->all()))->concat($matches)->unique('id')->values();
    }

    /**
     * Tasks that cannot become the parent of this one: itself and everything below it.
     *
     * @return list<int>
     */
    private function parentExclusions(): array
    {
        return [$this->task->getKey(), ...$this->descendantIds];
    }

    #[Computed]
    public function parentOptions(): Collection
    {
        return $this->pickerTasks('parent', $this->parentSearch, $this->parentId === '' ? [] : [$this->parentId], $this->parentExclusions(), withoutHeadings: true);
    }

    #[Computed]
    public function blockerOptions(): Collection
    {
        return $this->pickerTasks('blockers', $this->blockerSearch, $this->blockerIds, [$this->task->getKey()]);
    }

    #[Computed]
    public function blockingOptions(): Collection
    {
        return $this->pickerTasks('blocking', $this->blockingSearch, $this->blockingIds, [$this->task->getKey()]);
    }

    /**
     * Ids of the task and all of its descendants that can have subtasks (no section headings).
     *
     * @return list<int>
     */
    private function containerIds(): array
    {
        return array_values(array_diff([$this->task->getKey(), ...$this->descendantIds], $this->sectionIds));
    }

    private function resetSubtaskCaches(): void
    {
        unset($this->subtreeTasks, $this->childrenMap, $this->descendantIds, $this->sectionIds, $this->progress);

        $this->announceChange();
    }

    /**
     * An automation may have changed the task while it was saved (status, assignee, dates, tags, fields). The form shows
     * what is stored now, so saving again does not undo the rule.
     */
    private function showChangesByAutomations(): void
    {
        $this->task->refresh();

        $this->statusId = (string) $this->task->status_id;
        $this->assigneeId = (string) ($this->task->assignee_id ?? '');
        $this->dueDate = $this->task->due_date?->format('Y-m-d') ?? '';
        $this->startDate = $this->task->start_date?->format('Y-m-d') ?? '';
        $this->fillRepeatRule();
        $this->tagIds = $this->task->tags->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->collaboratorIds = $this->storedCollaboratorIds();
        $this->fieldValues = $this->storedFieldValues();
    }

    /**
     * The collaborators as stored, as the form keeps them. Handing a task over makes the person who had it one, without the form
     * having asked for it.
     *
     * @return list<string>
     */
    private function storedCollaboratorIds(): array
    {
        return $this->task->collaborators()->pluck('users.id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * The repeat rule as stored. Finishing a recurring task hands the rule on to the next one, so the form must not
     * keep the old rule or the next save would write it back.
     */
    private function fillRepeatRule(): void
    {
        $this->repeatUnit = $this->task->repeat_unit?->value ?? '';
        $this->repeatInterval = (string) ($this->task->repeat_interval ?? 1);
        $this->repeatMode = $this->task->repeat_mode?->value ?? RepeatMode::Schedule->value;
        $this->repeatUntil = $this->task->repeat_until?->format('Y-m-d') ?? '';
    }

    /**
     * The task's field values as the form holds them: field id => option id or value.
     *
     * @return array<int, string>
     */
    private function storedFieldValues(): array
    {
        return $this->task->fieldValues()->get()
            ->mapWithKeys(fn ($value) => [$value->custom_field_id => (string) ($value->option_id ?? $value->value)])
            ->all();
    }

    /**
     * Lets the list or board behind the flyout refresh itself.
     */
    private function announceChange(): void
    {
        if ($this->panel) {
            $this->dispatch('task-changed');
        }
    }

    public function addSubtask(int $parentId): void
    {
        $this->authorizeEdit();

        $this->createChild($parentId, isSection: false);
    }

    public function addSection(int $parentId): void
    {
        $this->authorizeEdit();

        $this->createChild($parentId, isSection: true);
    }

    private function createChild(int $parentId, bool $isSection): void
    {
        abort_unless(in_array($parentId, $this->containerIds(), true), 404);

        $this->validate([
            "newSubtaskTitles.$parentId" => ['required', 'string', 'max:255'],
        ], attributes: ["newSubtaskTitles.$parentId" => __('Title')]);

        $child = $this->task->project->tasks()->create([
            'parent_id' => $parentId,
            'is_section' => $isSection,
            'title' => trim($this->newSubtaskTitles[$parentId]),
            'creator_id' => auth()->id(),
            'position' => Task::nextPositionIn($this->task->project->tasks()->where('parent_id', $parentId)),
        ]);

        if ($isSection) {
            $this->sectionTitles[$child->id] = $child->title;
        }

        unset($this->newSubtaskTitles[$parentId]);
        $this->resetSubtaskCaches();
    }

    public function toggleSubtask(int $subtaskId): void
    {
        $this->authorizeEdit();

        abort_unless(in_array($subtaskId, $this->descendantIds, true) && ! in_array($subtaskId, $this->sectionIds, true), 404);

        $this->task->project->tasks()->findOrFail($subtaskId)->toggleDone();

        $this->resetSubtaskCaches();
    }

    public function updatedSectionTitles(string $value, string $sectionId): void
    {
        $this->authorizeEdit();

        abort_unless(in_array((int) $sectionId, $this->sectionIds, true) && in_array((int) $sectionId, $this->descendantIds, true), 404);

        $title = trim($value);

        if ($title === '' || mb_strlen($title) > 255) {
            $this->sectionTitles[$sectionId] = $this->subtreeTasks->firstWhere('id', (int) $sectionId)->title;
            Flux::toast(variant: 'danger', text: __('The name must not be empty and may have at most :max characters.', ['max' => 255]));

            return;
        }

        $this->task->project->tasks()->whereKey($sectionId)->update(['title' => $title]);
        $this->resetSubtaskCaches();
        $this->confirmSaved();
    }

    public function deleteSection(int $sectionId): void
    {
        $this->authorizeEdit();

        abort_unless(in_array($sectionId, $this->sectionIds, true) && in_array($sectionId, $this->descendantIds, true), 404);

        $this->task->project->tasks()->whereKey($sectionId)->delete();
        unset($this->sectionTitles[$sectionId]);
        $this->resetSubtaskCaches();
    }

    public function moveSubtask(int|string $itemId, int $position, int|string $parentId): void
    {
        $this->authorizeEdit();

        $itemId = (int) $itemId;
        $parentId = (int) $parentId;

        abort_unless(in_array($itemId, $this->descendantIds, true), 404);
        abort_unless(in_array($parentId, $this->containerIds(), true), 404);

        abort_if($parentId === $itemId || in_array($parentId, $this->idsBelow($itemId), true), 422);

        $this->task->project->tasks()->findOrFail($parentId)
            ->placeChild($this->task->project->tasks()->findOrFail($itemId), $position);

        $this->resetSubtaskCaches();
    }

    protected function mentionProject(): Project
    {
        return $this->task->project;
    }

    #[Computed]
    public function projectTags(): Collection
    {
        return $this->task->project->tags()->orderBy('name')->get();
    }

    /**
     * What the tag picker offers: all tags of the project once it is loaded, before that the tags of the task.
     *
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function tagOptions(): Collection
    {
        if ($this->pickerLoaded('tags')) {
            return $this->projectTags;
        }

        return $this->task->tags->whereIn('id', array_map(intval(...), $this->tagIds))->sortBy('name')->values();
    }

    public function createTag(): void
    {
        $this->authorizeEdit();

        $validated = $this->validate([
            'newTag' => ['required', 'string', 'max:50'],
        ], attributes: ['newTag' => __('Tag')]);

        $tag = $this->task->project->findOrCreateTag($validated['newTag']);

        $this->tagIds = array_values(array_unique([...$this->tagIds, (string) $tag->id]));
        $this->reset('newTag');
        $this->loadedPickers = array_values(array_unique([...$this->loadedPickers, 'tags']));
        unset($this->projectTags, $this->tagOptions);
    }

    #[Computed]
    public function users(): Collection
    {
        $ids = $this->task->project->eligibleUsers()->pluck('users.id')
            ->merge($this->task->collaborators()->pluck('users.id'))
            ->push($this->task->assignee_id)
            ->filter()
            ->unique();

        return User::whereIn('id', $ids)->orderBy('name')->get(self::USER_COLUMNS);
    }

    /**
     * The people the assignee and collaborator pickers hold at the start: the chosen ones.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function chosenUsers(): Collection
    {
        $ids = array_values(array_filter([$this->assigneeId, ...$this->collaboratorIds]));

        if ($ids === []) {
            return new Collection;
        }

        // The ids come from the browser: only people the page offers anyway (the project's people, the collaborators, the assignee) show up
        return User::whereIn('id', $ids)
            ->where(fn ($people) => $people
                ->whereIn('id', $this->task->project->eligibleUsers()->select('users.id'))
                ->orWhereIn('id', $this->task->collaborators()->select('users.id'))
                ->orWhere('id', $this->task->assignee_id))
            ->orderBy('name')->get(self::USER_COLUMNS);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function assigneeOptions(): Collection
    {
        return $this->pickerLoaded('people') ? $this->users : $this->chosenUsers->whereIn('id', [(int) $this->assigneeId])->values();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function collaboratorOptions(): Collection
    {
        return $this->pickerLoaded('people') ? $this->users : $this->chosenUsers->whereIn('id', array_map(intval(...), $this->collaboratorIds))->values();
    }

    /**
     * The description as the page opens it: rendered, or null while there is none (then the field is open right away).
     */
    #[Computed]
    public function descriptionPreview(): ?HtmlString
    {
        return trim((string) $this->description) === '' ? null : MarkdownService::render($this->description);
    }

    public function save(): void
    {
        $this->authorizeEdit();

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:'.Task::MAX_DESCRIPTION_LENGTH],
            'statusId' => ['required', Rule::in($this->task->project->statuses->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'assigneeId' => ['nullable', Rule::in($this->users->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'dueDate' => ['nullable', 'date'],
            'startDate' => ['nullable', 'date', 'before_or_equal:dueDate'],
            'repeatUnit' => ['nullable', Rule::enum(RepeatUnit::class)],
            'repeatInterval' => ['required', 'integer', 'min:1', 'max:365'],
            'repeatMode' => ['required', Rule::enum(RepeatMode::class)],
            'repeatUntil' => ['nullable', 'date', 'after_or_equal:dueDate'],
            ...$this->fieldRules(),
            'tagIds' => ['array'],
            'tagIds.*' => ['integer', Rule::exists('tags', 'id')->where('project_id', $this->task->project_id)],
            'parentId' => ['nullable', Rule::exists('tasks', 'id')->where('project_id', $this->task->project_id)->where('is_section', false)->whereNotIn('id', $this->parentExclusions())],
            'collaboratorIds' => ['array'],
            'collaboratorIds.*' => ['integer', Rule::in($this->users->pluck('id')->all())],
            'blockerIds' => ['array'],
            'blockerIds.*' => ['integer', Rule::exists('tasks', 'id')->where('project_id', $this->task->project_id)->whereNot('id', $this->task->getKey())],
            'blockingIds' => ['array'],
            'blockingIds.*' => ['integer', Rule::exists('tasks', 'id')->where('project_id', $this->task->project_id)->whereNot('id', $this->task->getKey())],
        ], attributes: [
            'title' => __('Title'),
            'description' => __('Description'),
            'statusId' => __('Status'),
            'assigneeId' => __('Assignee'),
            'dueDate' => __('Due on'),
            'startDate' => __('Starts on'),
            'repeatUnit' => __('Repeat'),
            'repeatInterval' => __('Every'),
            'repeatMode' => __('Calculated'),
            'repeatUntil' => __('Until'),
            ...$this->customFields->mapWithKeys(fn (CustomField $field) => ["fieldValues.{$field->id}" => $field->name])->all(),
            'tagIds.*' => __('Tags'),
            'parentId' => __('Parent task'),
            'collaboratorIds.*' => __('Collaborators'),
            'blockerIds.*' => __('Blocked by'),
            'blockingIds.*' => __('Blocking'),
        ]);

        if ($validated['repeatUnit'] !== '' && $validated['repeatUnit'] !== null && ($validated['dueDate'] ?? '') === '') {
            throw ValidationException::withMessages([
                'dueDate' => __('A recurring task needs a due date.'),
            ]);
        }

        if (array_intersect($validated['blockerIds'], $validated['blockingIds']) !== []) {
            throw ValidationException::withMessages([
                'blockingIds' => __('A task cannot block and be blocked at the same time.'),
            ]);
        }

        $dependencyChanges = DB::transaction(function () use ($validated) {
            $blockers = $this->task->blockers()->sync($validated['blockerIds']);
            $blocking = $this->task->blocking()->sync($validated['blockingIds']);

            if ($this->task->hasDependencyCycle()) {
                throw ValidationException::withMessages([
                    'blockingIds' => __('These dependencies would form a cycle.'),
                ]);
            }

            return ['blockers' => $blockers, 'blocking' => $blocking];
        });

        // What the form changed is measured against what was stored before saving: an automation that fires during the
        // save may add tags or set fields, and those must not be undone by the form's unchanged values
        $tagsBefore = $this->task->tags()->pluck('tags.id')->all();
        $fieldsBefore = $this->storedFieldValues();

        $parentChanged = ($validated['parentId'] ?: null) !== $this->task->parent_id;
        $previousAssigneeId = $this->task->assignee_id === null ? null : (int) $this->task->assignee_id;
        $collaboratorsBefore = $this->storedCollaboratorIds();

        // Before the change is saved, so that what a rule does about it reaches the person who had the task too
        $this->task->keepPreviousAssigneeAsCollaborator($previousAssigneeId, ($validated['assigneeId'] ?: null) === null ? null : (int) $validated['assigneeId']);

        $this->task->update([
            'title' => trim((string) preg_replace('/\s*\R\s*/u', ' ', $validated['title'])),
            'description' => $validated['description'] ?: null,
            'status_id' => $validated['statusId'],
            'parent_id' => $validated['parentId'] ?: null,
            'position' => $parentChanged
                ? Task::nextPositionIn($this->task->project->tasks()->where('parent_id', $validated['parentId'] ?: null))
                : $this->task->position,
            'assignee_id' => $validated['assigneeId'] ?: null,
            'due_date' => $validated['dueDate'] ?: null,
            'start_date' => $validated['startDate'] ?: null,
            'repeat_unit' => $validated['repeatUnit'] ?: null,
            'repeat_interval' => (int) $validated['repeatInterval'],
            'repeat_mode' => $validated['repeatMode'],
            'repeat_until' => ($validated['repeatUnit'] ?: null) ? ($validated['repeatUntil'] ?: null) : null,
        ]);

        $this->saveFieldValues($validated['fieldValues'] ?? [], $fieldsBefore);

        $tagChanges = $this->task->tags()->sync($this->tagsToKeep(
            $tagsBefore,
            array_map(intval(...), $validated['tagIds']),
            $this->task->tags()->pluck('tags.id')->all(),
        ));
        // Whoever joined while saving (the person who handed the task over, or somebody a rule added) is not undone by the form's list, which
        // was read before; and the assignee is whoever has the task now, which a rule may have changed
        $assigneeNow = (string) Task::query()->whereKey($this->task->getKey())->value('assignee_id');
        $joinedWhileSaving = array_diff($this->storedCollaboratorIds(), $collaboratorsBefore);
        $collaboratorChanges = $this->task->collaborators()->sync(
            array_values(array_diff(array_unique([...$validated['collaboratorIds'], ...$joinedWhileSaving]), [$assigneeNow]))
        );

        $names = fn (string $model, string $column) => fn (array $ids) => $model::whereIn('id', $ids)->orderBy($column)->pluck($column)->all();
        $this->task->logSyncChanges(ActivityType::TagsAdded, ActivityType::TagsRemoved, $tagChanges, $names(Tag::class, 'name'));
        $this->task->logSyncChanges(ActivityType::CollaboratorsAdded, ActivityType::CollaboratorsRemoved, $collaboratorChanges, $names(User::class, 'name'));
        $this->task->logSyncChanges(ActivityType::BlockersAdded, ActivityType::BlockersRemoved, $dependencyChanges['blockers'], $names(Task::class, 'title'));
        $this->task->logSyncChanges(ActivityType::BlockingAdded, ActivityType::BlockingRemoved, $dependencyChanges['blocking'], $names(Task::class, 'title'));
        $this->showChangesByAutomations();
        $this->forgetComments();

        $this->announceChange();

        Flux::toast(variant: 'success', text: __('Saved.'));
        $this->dispatch('task-saved');
    }

    /**
     * The latest comments, newest first, as many as the comments and the activity show, from one query.
     *
     * @return Collection<int, \App\Models\Comment>
     */
    #[Computed]
    public function latestComments(): Collection
    {
        return $this->task->comments()->with('user', 'reactions.user')->latest()->latest('id')->limit(max($this->commentLimit, $this->feedLimit))->get()
            ->each(fn ($comment) => $comment->setRelation('task', $this->task));
    }

    /**
     * The latest comments (commentLimit of them), oldest first, with the form below them.
     *
     * @return Collection<int, \App\Models\Comment>
     */
    #[Computed]
    public function shownComments(): Collection
    {
        return $this->latestComments->take($this->commentLimit)->reverse()->values();
    }

    /**
     * Whether there are older comments than the ones shown. Fewer comments fetched than asked for means there are no more.
     */
    #[Computed]
    public function hasEarlierComments(): bool
    {
        return $this->latestComments->count() >= $this->commentLimit && $this->task->comments()->count() > $this->commentLimit;
    }

    public function showEarlierComments(): void
    {
        $this->commentLimit += self::FEED_PAGE;
        unset($this->latestComments, $this->shownComments, $this->hasEarlierComments);
    }

    /**
     * The latest recorded changes and who commented when (feedLimit of them), oldest first. A comment shows here only
     * as "… commented" with a jump to it; the words stay in the comments above.
     *
     * @return \Illuminate\Support\Collection<int, array{at: \Illuminate\Support\Carbon, comment: ?\App\Models\Comment, activity: ?\App\Models\TaskActivity}>
     */
    #[Computed]
    public function activityFeed(): SupportCollection
    {
        $comments = $this->latestComments->take($this->feedLimit)
            ->map(fn ($comment) => ['at' => $comment->created_at, 'comment' => $comment, 'activity' => null]);

        $activities = $this->latestActivities
            ->map(fn ($activity) => ['at' => $activity->created_at, 'comment' => null, 'activity' => $activity]);

        return $comments->concat($activities)->sortByDesc('at')->take($this->feedLimit)->reverse()->values();
    }

    /**
     * @return Collection<int, \App\Models\TaskActivity>
     */
    #[Computed]
    public function latestActivities(): Collection
    {
        return $this->task->activities()->with('user')->latest()->latest('id')->limit($this->feedLimit)->get();
    }

    /**
     * Whether there are older entries than the ones shown. Counting is only needed when a kind has filled its page.
     */
    #[Computed]
    public function hasEarlierFeed(): bool
    {
        $comments = $this->latestComments->take($this->feedLimit)->count();
        $activities = $this->latestActivities->count();

        if ($comments < $this->feedLimit && $activities < $this->feedLimit) {
            return $comments + $activities > $this->feedLimit;
        }

        return $this->task->comments()->count() + $this->task->activities()->count() > $this->feedLimit;
    }

    public function showEarlierFeed(): void
    {
        $this->feedLimit += self::FEED_PAGE;
        unset($this->latestComments, $this->latestActivities, $this->activityFeed, $this->hasEarlierFeed, $this->shownComments, $this->hasEarlierComments);
    }

    /**
     * From "… commented" in the activity to the comment itself, loading earlier comments first if it is not shown yet.
     */
    public function showComment(int $commentId): void
    {
        $comment = $this->commentOrFail($commentId);

        $newerOrSame = $this->task->comments()
            ->where(fn ($query) => $query->where('created_at', '>', $comment->created_at)
                ->orWhere(fn ($query) => $query->where('created_at', $comment->created_at)->where('id', '>=', $comment->id)))
            ->count();

        if ($newerOrSame > $this->commentLimit) {
            $this->commentLimit = (int) (ceil($newerOrSame / self::FEED_PAGE) * self::FEED_PAGE);
            unset($this->latestComments, $this->shownComments, $this->hasEarlierComments);
        }

        $this->js("window.showComment({$comment->id})");
    }

    /**
     * Comments and the activity both list comments, so a change to one is a change to both.
     */
    private function forgetComments(): void
    {
        unset($this->latestComments, $this->latestActivities, $this->shownComments, $this->hasEarlierComments, $this->activityFeed, $this->hasEarlierFeed);
    }

    public function addComment(): void
    {
        $this->authorizeEdit();

        $validated = $this->validate(['comment' => ['required', 'string', 'max:'.Comment::MAX_BODY_LENGTH]], attributes: ['comment' => __('Comment')]);

        $this->task->comments()->create([
            'user_id' => auth()->id(),
            'body' => $validated['comment'],
        ]);

        $this->reset('comment');
        $this->forgetComments();
    }

    private function commentOrFail(int $commentId): \App\Models\Comment
    {
        return $this->task->comments()->findOrFail($commentId)->setRelation('task', $this->task);
    }

    /**
     * Only the author changes the words; the author and project admins may delete a comment.
     */
    public function startEditComment(int $commentId): void
    {
        $comment = $this->commentOrFail($commentId);

        Gate::authorize('update', $comment);

        $this->editingCommentId = $comment->id;
        $this->editingBody = $comment->body;
    }

    public function cancelEditComment(): void
    {
        $this->reset('editingCommentId', 'editingBody');
    }

    public function saveComment(): void
    {
        $this->authorizeEdit();

        $comment = $this->commentOrFail((int) $this->editingCommentId);

        Gate::authorize('update', $comment);

        $validated = $this->validate(['editingBody' => ['required', 'string', 'max:'.Comment::MAX_BODY_LENGTH]], attributes: ['editingBody' => __('Comment')]);

        $comment->update(['body' => $validated['editingBody']]);

        $this->reset('editingCommentId', 'editingBody');
        $this->forgetComments();
    }

    public function deleteComment(int $commentId): void
    {
        $comment = $this->commentOrFail($commentId);

        Gate::authorize('delete', $comment);

        $comment->delete();

        if ($this->editingCommentId === $commentId) {
            $this->reset('editingCommentId', 'editingBody');
        }

        $this->forgetComments();
    }

    #[Computed]
    public function attachments(): Collection
    {
        return $this->task->attachments()->with('user')->orderBy('id')->get();
    }

    public function updatedUploads(): void
    {
        $this->authorizeEdit();

        $this->validate(['uploads' => ['array', 'max:20'], 'uploads.*' => ['file', 'max:'.Attachment::MAX_KILOBYTES]], [], ['uploads.*' => __('File')]);

        $names = [];

        foreach ($this->uploads as $upload) {
            $names[] = $this->task->attachUpload($upload)->name;
        }

        $this->reset('uploads');
        $this->task->logActivity(ActivityType::AttachmentsAdded, ['names' => $names]);
        unset($this->attachments);
        $this->forgetComments();
    }

    /**
     * Stores the image pasted or dropped into a text as an attachment and returns what the text needs to refer to it.
     *
     * @return array{id: int, name: string}|null
     */
    public function storeInlineImage(): ?array
    {
        $this->authorizeEdit();

        $this->validate(['inlineUpload' => ['required', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:'.Attachment::MAX_KILOBYTES]], [], ['inlineUpload' => __('File')]);

        $attachment = $this->task->attachUpload($this->inlineUpload);

        $this->reset('inlineUpload');
        $this->task->logActivity(ActivityType::AttachmentsAdded, ['names' => [$attachment->name]]);
        unset($this->attachments);
        $this->forgetComments();

        return ['id' => $attachment->id, 'name' => $attachment->name];
    }

    /**
     * Image attachments the texts of this task can show.
     *
     * @return list<array{id: int, name: string}>
     */
    #[Computed]
    public function imageAttachments(): array
    {
        return $this->attachments
            ->filter(fn (Attachment $attachment) => $attachment->previewKind() === 'image')
            ->map(fn (Attachment $attachment) => ['id' => $attachment->id, 'name' => $attachment->name])
            ->values()
            ->all();
    }

    public function deleteAttachment(int $attachmentId): void
    {
        $this->authorizeEdit();

        $attachment = $this->task->attachments()->findOrFail($attachmentId);
        $attachment->delete();

        $this->task->logActivity(ActivityType::AttachmentsRemoved, ['names' => [$attachment->name]]);
        unset($this->attachments);
        $this->forgetComments();
    }

    public function duplicate(): void
    {
        $this->authorizeEdit();

        abort_if($this->task->is_section, 404);

        $copy = $this->task->duplicate();
        $this->forgetComments();

        if ($this->panel) {
            $this->dispatch('task-changed');
            $this->dispatch('open-task', id: $copy->id);

            return;
        }

        Flux::toast(variant: 'success', text: __('Task duplicated.'));
        $this->redirectRoute('tasks.show', $copy, navigate: true);
    }

    public function delete(): void
    {
        $this->authorizeEdit();

        $projectId = $this->task->project_id;
        $this->task->delete();

        if ($this->panel) {
            $this->dispatch('task-deleted');

            return;
        }

        $this->redirectRoute('projects.show', $projectId, navigate: true);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function projectChangedElsewhere(array $event = []): void
    {
        $taskId = $event['task_id'] ?? null;

        if (($event['kind'] ?? null) === 'task' && $taskId === $this->task->getKey()) {
            $this->changedElsewhere = true;

            return;
        }

        // Only changes to this task, its comments and attachments, its subtasks or many tasks at once concern this page.
        if ($taskId !== null && $taskId !== $this->task->getKey() && ! in_array($taskId, $this->descendantIds, true)) {
            $this->skipRender();
        }
    }

    public function reloadFromServer(): void
    {
        $this->task->refresh();
        $this->mount();
        $this->changedElsewhere = false;
        $this->resetErrorBag();

        // The editors show the text of the server again
        $this->dispatch('task-saved');
    }

    public function presenceChannel(): string
    {
        return "task.{$this->task->getKey()}.presence";
    }

    public function rendering(View $view): void
    {
        $this->task->loadMissing('reactions.user');

        if (! $this->panel) {
            $view->title($this->task->title);
        }
    }
};
?>

<div @class(['max-w-4xl' => ! $panel])>
    <x-presence :channel="$this->presenceChannel()" :one="__(':name is looking at this task too')" :many="__(':count people are looking at this task too')" class="mb-3" />

    @if ($changedElsewhere)
        <flux:callout class="mb-4" variant="warning" icon="arrow-path" :heading="__('Changed by someone else')" :text="__('This task was saved by another person. Reload to see their changes; what you typed is kept until then.')">
            <x-slot name="actions">
                <flux:button size="sm" wire:click="reloadFromServer">{{ __('Reload') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    @if ($panel)
        {{-- Stays at the top of the flyout while its content scrolls: sticky counts from inside the flyout's 1.25rem of padding, so -top-5. Above the bar with the buttons (z-10), which sticks inside the form and moves up behind this header once the form has scrolled out of sight --}}
        <div class="sticky -top-5 z-20 -mx-5 mb-2 flex items-center gap-2 bg-white px-5 py-2 dark:bg-zinc-800">
            <x-project-icon :project="$task->project" size="sm" />
            <flux:text size="sm" class="min-w-0 flex-1 truncate">
                {{ $task->project->name }}
                @foreach ($this->ancestors as $ancestor)
                    <span class="text-zinc-400">/</span>
                    <button type="button" wire:key="ancestor-{{ $ancestor->id }}" x-on:click="$dispatch('open-task', { id: {{ $ancestor->id }} })" class="hover:underline">{{ $ancestor->title }}</button>
                @endforeach
            </flux:text>
            {{-- The address bar of the flyout is that of the list (with its filters and sorting): this one leads to the task itself --}}
            <flux:button size="sm" variant="ghost" icon="link" x-on:click="$copyText({{ Js::from(route('tasks.show', $task)) }}, {{ Js::from(__('Link copied.')) }}, {{ Js::from(__('The link could not be copied.')) }})" aria-label="{{ __('Copy link') }}" tooltip="{{ __('Copy link') }}" />
            <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square" href="{{ route('tasks.show', $task) }}" wire:navigate aria-label="{{ __('Open as page') }}" tooltip="{{ __('Open as page') }}" />
            <flux:button size="sm" variant="ghost" icon="x-mark" x-on:click="$dispatch('close-task')" data-flyout-close aria-label="{{ __('Close') }}" tooltip="{{ __('Close') }}" />
        </div>
    @else
        <x-project-breadcrumbs :project="$task->project" :current="__('Task')">
            @foreach ($this->ancestors as $ancestor)
                <flux:breadcrumbs.item href="{{ route('tasks.show', $ancestor) }}" wire:navigate>{{ $ancestor->title }}</flux:breadcrumbs.item>
            @endforeach
        </x-project-breadcrumbs>
    @endif

    @unless ($this->canEdit)
        @if ($task->project->archived_at)
            <flux:callout class="mb-4" icon="archive-box" :heading="__('Archived')" :text="__('This project is archived and read-only.')" />
        @else
            <flux:callout class="mb-4" icon="eye" :heading="__('View only')" :text="__('In this project you can read tasks but not change them.')" />
        @endif
    @endunless

    {{-- data-task-form: the flyout asks before it closes while this form holds changes that are not saved (see taskFlyout in app.js).
         data-dirty-properties: the properties that "Save" stores, the ones that count (not searches, not what saves itself). --}}
    <form wire:submit="save" data-task-form data-dirty-properties="{{ json_encode(['title', 'description', 'statusId', 'assigneeId', 'startDate', 'dueDate', 'repeatUnit', 'repeatInterval', 'repeatMode', 'repeatUntil', 'fieldValues', 'tagIds', 'parentId', 'collaboratorIds', 'blockerIds', 'blockingIds']) }}" class="space-y-4">
        {{-- The title as a heading and the properties as a quiet list: both look like text until pointed at or focused --}}
        {{-- Long titles wrap instead of being cut off; Enter saves as in a single line, a pasted line break becomes a space --}}
        <flux:textarea wire:model="title" :aria-label="__('Title')" rows="1" resize="none" class="task-title-input field-sizing-content"
            x-on:keydown.enter.prevent="$el.form.requestSubmit()" />
        {{-- A task with a description opens on the rendered text; "Edit" opens the field --}}
        <x-markdown-editor wire:model="description" :label="__('Description')" grow :max="Task::MAX_DESCRIPTION_LENGTH" :preview="$this->descriptionPreview !== null" :preview-html="$this->descriptionPreview" :placeholder="__('Describe the task … (Markdown, @ for mentions)')" :rows="3" mentions-on-demand :images="$this->canEdit ? $this->imageAttachments : null" />

        <x-reactions :reactable="$task" target="task" :can-react="$this->canEdit" />

        <div class="task-properties">
            <x-task-field :label="__('Status')" icon="signal">
                <flux:select size="sm" variant="listbox" wire:model="statusId">
                    <x-slot name="trigger"><flux:select.button size="sm" :aria-label="__('Status')" :invalid="$errors->has('statusId')" /></x-slot>
                    @foreach ($task->project->statuses as $status)
                        <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </x-task-field>

            <x-task-field :label="__('Assignee')" icon="user" lazy="people">
                <flux:select size="sm" variant="listbox" wire:model="assigneeId" data-shortcut-assignee>
                    <x-slot name="trigger"><flux:select.button size="sm" :aria-label="__('Assignee')" :invalid="$errors->has('assigneeId')" /></x-slot>
                    <flux:select.option value="">{{ __('Nobody') }}</flux:select.option>
                    @island(name: 'assignee-choices', always: true)
                        @foreach ($this->assigneeOptions as $user)
                            <flux:select.option variant="listbox" wire:key="assignee-{{ $user->id }}" value="{{ $user->id }}">{{ $user->labelledName() }}</flux:select.option>
                        @endforeach
                    @endisland
                </flux:select>
            </x-task-field>

            <x-task-field :label="__('Dates')" icon="calendar">
                {{-- The words below a field ("Tomorrow") follow the date as soon as it is picked, saved or not; they come from DateService. --}}
                <div class="grid gap-2 sm:grid-cols-2" x-data="{ words: @js(\App\Services\DateService::relativeLabelsByDate(auth()->user())) }">
                    <div>
                        <flux:date-picker size="sm" wire:model="startDate" :aria-label="__('Starts on')" :placeholder="__('Starts on')" locale="{{ app()->getLocale() }}" clearable />
                        <flux:text size="sm" class="mt-1" x-cloak x-show="words[$wire.startDate]" x-text="words[$wire.startDate]" />
                    </div>
                    <div>
                        <flux:date-picker size="sm" wire:model="dueDate" :aria-label="__('Due on')" :placeholder="__('Due on')" locale="{{ app()->getLocale() }}" clearable />
                        <flux:text size="sm" class="mt-1" x-cloak x-show="words[$wire.dueDate]" x-text="words[$wire.dueDate]" />
                    </div>
                </div>
            </x-task-field>

            <x-task-field :label="__('Recurrence')" icon="arrow-path">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:select size="sm" variant="listbox" wire:model.live="repeatUnit" placeholder="{{ __('Never') }}" class="!w-36">
                        <x-slot name="trigger"><flux:select.button size="sm" :aria-label="__('Repeat')" :invalid="$errors->has('repeatUnit')" /></x-slot>
                        <flux:select.option value="">{{ __('Never') }}</flux:select.option>
                        @foreach (\App\Enums\RepeatUnit::cases() as $unit)
                            <flux:select.option value="{{ $unit->value }}">{{ $unit->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($repeatUnit !== '')
                        <flux:input size="sm" wire:model="repeatInterval" type="number" min="1" max="365" :aria-label="__('Every')" :placeholder="__('Every')" class="!w-20" />
                        <flux:select size="sm" variant="listbox" wire:model="repeatMode" class="!w-44">
                            <x-slot name="trigger"><flux:select.button size="sm" :aria-label="__('Calculated')" :invalid="$errors->has('repeatMode')" /></x-slot>
                            @foreach (\App\Enums\RepeatMode::cases() as $mode)
                                <flux:select.option value="{{ $mode->value }}">{{ $mode->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:date-picker size="sm" wire:model="repeatUntil" :aria-label="__('Until')" :placeholder="__('Until')" locale="{{ app()->getLocale() }}" clearable />
                    @endif
                </div>
                @if ($repeatUnit !== '')
                    <flux:text size="sm" class="mt-1">{!! __('Once you complete the task, the next one is created (with assignees, tags, fields and subtasks). <em>On schedule</em> counts from the due date, <em>after completion</em> from the day you complete it. A due date is required.') !!}</flux:text>
                @endif
            </x-task-field>

            @foreach ($this->customFields as $field)
                <x-task-field :label="$field->name" icon="adjustments-horizontal" wire:key="field-{{ $field->id }}">
                    <x-custom-field-input :field="$field" model="fieldValues.{{ $field->id }}" size="sm" />
                </x-task-field>
            @endforeach

            <x-task-field :label="__('Parent task')" icon="arrow-turn-left-up" lazy="parent">
                <flux:select size="sm" variant="listbox" wire:model="parentId" searchable>
                    <x-slot name="trigger"><flux:select.button size="sm" :aria-label="__('Parent task')" :invalid="$errors->has('parentId')" /></x-slot>
                    <x-slot name="search">
                        <flux:select.search wire:model.live.debounce.300ms="parentSearch" :placeholder="__('Search tasks …')" />
                    </x-slot>
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @island(name: 'parent-choices', always: true)
                        @foreach ($this->parentOptions as $candidate)
                            <flux:select.option variant="listbox" wire:key="parent-{{ $candidate->id }}" value="{{ $candidate->id }}">{{ $candidate->title }}</flux:select.option>
                        @endforeach
                    @endisland
                </flux:select>
            </x-task-field>

            <x-task-field :label="__('Collaborators')" icon="users" lazy="people">
                <flux:pillbox size="sm" wire:model="collaboratorIds" multiple searchable :placeholder="__('Choose more people …')">
                    <x-slot name="trigger"><flux:pillbox.trigger size="sm" :aria-label="__('Collaborators')" /></x-slot>
                    @island(name: 'collaborator-choices', always: true)
                        @foreach ($this->collaboratorOptions as $user)
                            <flux:pillbox.option wire:key="collaborator-{{ $user->id }}" value="{{ $user->id }}">{{ $user->labelledName() }}</flux:pillbox.option>
                        @endforeach
                    @endisland
                </flux:pillbox>
            </x-task-field>

            <x-task-field :label="__('Tags')" icon="tag" lazy="tags">
                <flux:pillbox size="sm" variant="combobox" wire:model="tagIds" multiple :aria-label="__('Tags')" :placeholder="__('Select tags …')">
                    @if ($this->canEdit)
                        <x-slot name="input">
                            <flux:pillbox.input wire:model="newTag" :placeholder="__('Select or create tags …')" />
                        </x-slot>
                    @endif

                    @island(name: 'tag-choices', always: true)
                        @foreach ($this->tagOptions as $tag)
                            <flux:pillbox.option wire:key="tag-{{ $tag->id }}" value="{{ $tag->id }}">{{ $tag->name }}</flux:pillbox.option>
                        @endforeach
                    @endisland

                    @if ($this->canEdit)
                        <flux:pillbox.option.create wire:click="createTag" min-length="1">
                            {!! __('Create tag “:name”', ['name' => '<span wire:text="newTag"></span>']) !!}
                        </flux:pillbox.option.create>
                    @endif
                </flux:pillbox>
            </x-task-field>

            <x-task-field :label="__('Blocked by')" icon="lock-closed" lazy="blockers">
                <flux:pillbox size="sm" wire:model="blockerIds" multiple searchable :placeholder="__('Choose tasks …')">
                    <x-slot name="trigger"><flux:pillbox.trigger size="sm" :aria-label="__('Blocked by')" /></x-slot>
                    <x-slot name="search">
                        <flux:pillbox.search wire:model.live.debounce.300ms="blockerSearch" :placeholder="__('Search tasks …')" />
                    </x-slot>
                    @island(name: 'blocker-choices', always: true)
                        @foreach ($this->blockerOptions as $other)
                            <flux:pillbox.option wire:key="blocker-{{ $other->id }}" value="{{ $other->id }}">{{ $other->title }}</flux:pillbox.option>
                        @endforeach
                    @endisland
                </flux:pillbox>
            </x-task-field>

            <x-task-field :label="__('Blocking')" icon="no-symbol" lazy="blocking">
                <flux:pillbox size="sm" wire:model="blockingIds" multiple searchable :placeholder="__('Choose tasks …')">
                    <x-slot name="trigger"><flux:pillbox.trigger size="sm" :aria-label="__('Blocking')" /></x-slot>
                    <x-slot name="search">
                        <flux:pillbox.search wire:model.live.debounce.300ms="blockingSearch" :placeholder="__('Search tasks …')" />
                    </x-slot>
                    @island(name: 'blocking-choices', always: true)
                        @foreach ($this->blockingOptions as $other)
                            <flux:pillbox.option wire:key="blocking-{{ $other->id }}" value="{{ $other->id }}">{{ $other->title }}</flux:pillbox.option>
                        @endforeach
                    @endisland
                </flux:pillbox>
            </x-task-field>

            <x-task-field :label="__('Emails about this task')" icon="envelope">
                <flux:switch wire:model.live="notificationsOn" :aria-label="__('Emails about this task')" />
                <flux:text size="sm" class="mt-1">{{ __('Assignees and collaborators get an email for new comments and status changes.') }}</flux:text>
            </x-task-field>
        </div>

        @if ($task->isBlocked())
            <flux:callout variant="warning" icon="lock-closed" :heading="__('This task is blocked')" :text="__('At least one task it depends on is not done yet.')" />
        @endif

        @if ($this->canEdit)
            {{-- On phones duplicate and delete show only their icon, so the row fits --}}
            {{-- In the flyout the bar stays at the bottom edge while the properties scroll: changes are only taken with "Save" --}}
            <div @class(['flex gap-3', 'sticky -bottom-5 z-10 -mx-5 border-t border-zinc-200 bg-white px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800' => $panel])>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <div class="sm:hidden"><flux:button type="button" icon="document-duplicate" square wire:click="duplicate" :aria-label="__('Duplicate')" :tooltip="__('Duplicate')" /></div>
                <flux:button type="button" icon="document-duplicate" class="max-sm:hidden" wire:click="duplicate">{{ __('Duplicate') }}</flux:button>
                <flux:spacer />
                <flux:modal.trigger name="delete-task">
                    <div class="sm:hidden"><flux:button variant="danger" icon="trash" square :aria-label="__('Delete')" :tooltip="__('Delete')" /></div>
                    <flux:button variant="danger" icon="trash" class="max-sm:hidden">{{ __('Delete') }}</flux:button>
                </flux:modal.trigger>
            </div>
        @endif
    </form>

    <flux:separator class="my-6" />

    <flux:heading size="lg" class="mb-2">{{ __('Subtasks') }}</flux:heading>

    @if ($this->progress)
        <div class="mb-4 max-w-sm">
            <flux:text size="sm" class="mb-1">{{ __(':done of :total done', ['done' => $this->progress['done'], 'total' => $this->progress['total']]) }}</flux:text>
            <flux:progress :value="intdiv($this->progress['done'] * 100, $this->progress['total'])" />
        </div>
    @endif

    @php($rootChildren = $this->childrenMap->get($task->id, collect()))
    @if ($rootChildren->isNotEmpty())
        <x-task-subtree :tasks="$rootChildren" :children-map="$this->childrenMap" :parent-id="$task->id" :can-edit="$this->canEdit" :panel="$panel" />
    @endif

    @if ($this->canEdit)
        <form wire:submit="addSubtask({{ $task->id }})" class="mt-3 flex flex-wrap items-center gap-2">
            <flux:input size="sm" wire:model="newSubtaskTitles.{{ $task->id }}" :aria-label="__('New subtask')" :placeholder="__('New subtask')" class="max-sm:basis-full sm:max-w-sm" />
            <flux:button size="sm" type="submit" icon="plus">{{ __('Add') }}</flux:button>
            <flux:button size="sm" type="button" icon="bars-3-bottom-left" wire:click="addSection({{ $task->id }})">{{ __('Heading') }}</flux:button>
        </form>
        <flux:error :name="'newSubtaskTitles.'.$task->id" />
    @endif

    <flux:separator class="my-6" />

    <flux:heading size="lg" class="mb-2">{{ __('Attachments') }}</flux:heading>

    <div>
        <ul class="space-y-3">
            @foreach ($this->attachments as $attachment)
                @php($kind = $attachment->previewKind())
                @php($inlineUrl = route('attachments.show', [$attachment, 'inline' => 1]))
                @php($preview = ['url' => $inlineUrl, 'download' => route('attachments.show', $attachment), 'name' => $attachment->name, 'kind' => $kind])
                <li wire:key="attachment-{{ $attachment->id }}" class="space-y-2">
                    <flux:file-item
                        :heading="$attachment->name"
                        :text="$attachment->humanSize().' · '.($attachment->user?->name ?? __('Someone')).' · '.auth()->user()->inTimezone($attachment->created_at)->isoFormat('L LT')"
                        :icon="match ($kind) { 'image' => 'photo', 'pdf' => 'document-text', 'text' => 'document', default => 'paper-clip' }"
                    >
                        <x-slot name="actions">
                            @if ($kind)
                                <flux:button size="sm" variant="ghost" icon="eye" inset x-on:click="$dispatch('preview-file', {{ Js::from($preview) }})" :aria-label="__('Preview')" :tooltip="__('Preview')" />
                            @endif
                            <flux:button size="sm" variant="ghost" icon="arrow-down-tray" inset :href="route('attachments.show', $attachment)" :aria-label="__('Download')" :tooltip="__('Download')" />
                            @if ($this->canEdit)
                                <flux:button size="sm" variant="ghost" icon="trash" inset wire:click="deleteAttachment({{ $attachment->id }})" wire:confirm="{{ __('Delete attachment “:name”?', ['name' => $attachment->name]) }}" :aria-label="__('Delete attachment')" :tooltip="__('Delete attachment')" />
                            @endif
                        </x-slot>
                    </flux:file-item>
                    @if ($kind === 'image')
                        <button type="button" class="block" x-on:click="$dispatch('preview-file', @js($preview))" aria-label="{{ __('Preview') }}">
                            <img src="{{ $inlineUrl }}" alt="{{ $attachment->name }}" class="max-h-64 max-w-full rounded-lg border border-zinc-200 object-contain dark:border-zinc-700" loading="lazy">
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    @if ($this->attachments->isEmpty())
        <flux:text>{{ __('No attachments yet.') }}</flux:text>
    @endif

    @if ($this->canEdit)
        <div class="mt-3">
            <flux:file-upload wire:model="uploads" multiple>
                <flux:file-upload.dropzone :heading="__('Drag files here or click')" :text="__('Up to :size MB per file', ['size' => intdiv(\App\Models\Attachment::MAX_KILOBYTES, 1024)])" with-progress inline />
            </flux:file-upload>
            <flux:error name="uploads" />
        </div>
    @endif

    <flux:separator class="my-6" />

    <flux:heading size="lg" class="mb-4">{{ __('Comments') }}</flux:heading>

    <div class="space-y-3">
        @if ($this->hasEarlierComments)
            <flux:button size="sm" variant="ghost" icon="chevron-up" wire:click="showEarlierComments">{{ __('Show earlier comments') }}</flux:button>
        @endif

        @forelse ($this->shownComments as $comment)
            <div id="comment-{{ $comment->id }}" wire:key="comment-{{ $comment->id }}" class="flex scroll-mt-4 items-start gap-3">
                @if ($comment->user)
                    <x-user-avatar size="xs" :user="$comment->user" class="mt-2" />
                @else
                    <flux:avatar circle size="xs" icon="bolt" class="mt-2" />
                @endif

                <flux:card size="sm" class="min-w-0 flex-1 space-y-1 transition-shadow" data-comment-card>
                    <div class="flex items-center gap-2">
                        <flux:text class="min-w-0 flex-1 text-sm"><strong>{{ $comment->authorName() ?? __('Someone') }}</strong> · <x-time :at="$comment->created_at" />@if ($comment->wasEdited()) · {{ __('edited') }} @endif</flux:text>
                        @if ($editingCommentId !== $comment->id && Gate::allows('update', $comment))
                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="startEditComment({{ $comment->id }})" :aria-label="__('Edit comment')" :tooltip="__('Edit comment')" />
                        @endif
                        @can('delete', $comment)
                            <flux:button size="xs" variant="ghost" icon="trash" class="max-sm:size-10!" wire:click="deleteComment({{ $comment->id }})" wire:confirm="{{ __('Delete comment?') }}" :aria-label="__('Delete comment')" :tooltip="__('Delete comment')" />
                        @endcan
                    </div>

                    @if ($editingCommentId === $comment->id)
                        <form wire:submit="saveComment" class="space-y-2">
                            <x-markdown-editor wire:model="editingBody" :max="Comment::MAX_BODY_LENGTH" :rows="3" mentions-on-demand :images="$this->canEdit ? $this->imageAttachments : null" />
                            <flux:error name="editingBody" />
                            <div class="flex gap-2">
                                <flux:button size="sm" type="submit" variant="primary">{{ __('Save') }}</flux:button>
                                <flux:button size="sm" type="button" variant="ghost" wire:click="cancelEditComment">{{ __('Cancel') }}</flux:button>
                            </div>
                        </form>
                    @else
                        <x-markdown :text="$comment->body" />
                    @endif

                    <x-reactions :reactable="$comment" target="comment" :can-react="$this->canEdit" />
                </flux:card>
            </div>
        @empty
            <flux:text>{{ __('No comments yet.') }}</flux:text>
        @endforelse
    </div>

    @if ($this->canEdit)
        <form wire:submit="addComment" class="mt-6 space-y-3">
            <x-markdown-editor wire:model="comment" :max="Comment::MAX_BODY_LENGTH" :placeholder="__('Write a comment … (Markdown, @ for mentions)')" :rows="3" mentions-on-demand :images="$this->canEdit ? $this->imageAttachments : null" />
            <flux:button type="submit">{{ __('Post comment') }}</flux:button>
        </form>
    @endif

    <flux:separator class="my-6" />

    <flux:heading size="lg" class="mb-4">{{ __('Activity') }}</flux:heading>

    <div class="space-y-3">
        @if ($this->hasEarlierFeed)
            <flux:button size="sm" variant="ghost" icon="chevron-up" wire:click="showEarlierFeed">{{ __('Show earlier entries') }}</flux:button>
        @endif

        @if ($this->activityFeed->isEmpty())
            <flux:text>{{ __('No activity yet.') }}</flux:text>
        @else
            <flux:timeline size="sm">
                @foreach ($this->activityFeed as $entry)
                    @if ($entry['comment'])
                        @php($comment = $entry['comment'])
                        <flux:timeline.item wire:key="activity-comment-{{ $comment->id }}">
                            <flux:timeline.indicator>
                                <flux:icon name="chat-bubble-left" variant="micro" />
                            </flux:timeline.indicator>

                            <flux:timeline.content>
                                <flux:text size="sm"><strong class="font-medium text-zinc-800 dark:text-white">{{ $comment->authorName() ?? __('Someone') }}</strong> {{ __('commented') }} · <x-time :at="$comment->created_at" /> · <flux:link variant="ghost" href="#comment-{{ $comment->id }}" wire:click.prevent="showComment({{ $comment->id }})" class="text-[length:inherit]!">{{ __('Go to comment') }}</flux:link></flux:text>
                            </flux:timeline.content>
                        </flux:timeline.item>
                    @else
                        @php($activity = $entry['activity'])
                        <flux:timeline.item wire:key="activity-{{ $activity->id }}">
                            <flux:timeline.indicator>
                                <flux:icon :name="$activity->type->icon()" variant="micro" />
                            </flux:timeline.indicator>

                            <flux:timeline.content>
                                <flux:text size="sm"><strong class="font-medium text-zinc-800 dark:text-white">{{ $activity->actorName() }}</strong> {{ $activity->sentence() }} · <x-time :at="$activity->created_at" />@if ($activity->triggeredBy()) · {{ __('triggered by :name', ['name' => $activity->triggeredBy()]) }}@endif</flux:text>
                            </flux:timeline.content>
                        </flux:timeline.item>
                    @endif
                @endforeach
            </flux:timeline>
        @endif
    </div>

    <flux:modal name="delete-task" class="min-w-[22rem]">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Delete task?') }}</flux:heading>
            <flux:text>
                @if (count($this->descendantIds) - count($this->sectionIds) > 0)
                    {{ __('The task with :count subtasks and all comments and attachments will be permanently deleted.', ['count' => count($this->descendantIds) - count($this->sectionIds)]) }}
                @else
                    {{ __('The task and all comments and attachments will be permanently deleted.') }}
                @endif
            </flux:text>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
