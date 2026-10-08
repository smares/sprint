<?php

use App\Concerns\ListensForRealtime;
use App\Color;
use App\Enums\CustomFieldType;
use App\Enums\RepeatMode;
use App\Enums\RepeatUnit;
use App\Models\Attachment;
use App\Models\CustomField;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\MarkdownService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use ListensForRealtime;
    use WithFileUploads;

    /** How many tasks the pickers for parent task and dependencies list at most besides the chosen ones. */
    private const PICKER_LIMIT = 50;

    /** How many comments and changes the activity feed shows at first and adds per "show earlier". */
    private const FEED_PAGE = 50;

    /** How many tasks the @ suggestions offer at most. */
    private const MENTION_LIMIT = 8;

    public Task $task;

    /** Shown as a side panel next to a list or board instead of as a page of its own. */
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

    /** How many of the latest feed entries are shown. */
    public int $feedLimit = self::FEED_PAGE;

    public function hydrate(): void
    {
        Gate::authorize('view', $this->task->project);
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

        $this->title = $this->task->title;
        $this->description = $this->task->description ?? '';
        $this->statusId = (string) $this->task->status_id;
        $this->assigneeId = (string) ($this->task->assignee_id ?? '');
        $this->dueDate = $this->task->due_date?->format('Y-m-d') ?? '';
        $this->startDate = $this->task->start_date?->format('Y-m-d') ?? '';
        $this->repeatUnit = $this->task->repeat_unit?->value ?? '';
        $this->repeatInterval = (string) ($this->task->repeat_interval ?? 1);
        $this->repeatMode = $this->task->repeat_mode?->value ?? RepeatMode::Schedule->value;
        $this->repeatUntil = $this->task->repeat_until?->format('Y-m-d') ?? '';
        $this->fieldValues = $this->task->fieldValues
            ->mapWithKeys(fn ($value) => [$value->custom_field_id => (string) ($value->option_id ?? $value->value)])
            ->all();
        $this->notificationsOn = ! $this->task->isMutedBy(auth()->user());
        $this->parentId = (string) ($this->task->parent_id ?? '');
        $this->sectionTitles = $this->subtreeTasks->where('is_section', true)->pluck('title', 'id')->all();
        $this->tagIds = $this->task->tags->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->collaboratorIds = $this->task->collaborators()->pluck('users.id')->map(fn ($id) => (string) $id)->all();
        $this->blockerIds = $this->task->blockers()->pluck('tasks.id')->map(fn ($id) => (string) $id)->all();
        $this->blockingIds = $this->task->blocking()->pluck('tasks.id')->map(fn ($id) => (string) $id)->all();
    }

    public function updatedNotificationsOn(bool $value): void
    {
        $this->task->setMutedBy(auth()->user(), ! $value);
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
            CustomFieldType::Date => ['nullable', 'date'],
        }])->all();
    }

    private function displayFieldValue(CustomField $field, ?string $value): string
    {
        return match (true) {
            $value === null || $value === '' => '–',
            $field->type === CustomFieldType::Select => $field->options->firstWhere('id', (int) $value)?->name ?? '–',
            $field->type === CustomFieldType::Date => \Illuminate\Support\Carbon::parse($value)->isoFormat('L'),
            default => $value,
        };
    }

    /**
     * @param  array<int|string, string|null>  $input
     */
    private function saveFieldValues(array $input): void
    {
        $existing = $this->task->fieldValues()->get()->keyBy('custom_field_id');

        foreach ($this->customFields as $field) {
            $new = trim((string) ($input[$field->id] ?? ''));
            $new = $new === '' ? null : $new;
            $current = $existing->get($field->id);
            $old = $current === null ? null : (string) ($current->option_id ?? $current->value);

            if ($new === $old) {
                continue;
            }

            if ($new === null) {
                $current->delete();
            } else {
                $this->task->fieldValues()->updateOrCreate(
                    ['custom_field_id' => $field->id],
                    ['option_id' => $field->type === CustomFieldType::Select ? $new : null, 'value' => $field->type === CustomFieldType::Select ? null : $new],
                );
            }

            $this->task->logActivity('field_changed', [
                'name' => $field->name,
                'from' => $this->displayFieldValue($field, $old),
                'to' => $this->displayFieldValue($field, $new),
            ]);
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

        while ($frontier !== []) {
            $level = new Collection;

            foreach (array_chunk($frontier, 500) as $parents) {
                $level = $level->concat(
                    $this->task->project->tasks()->whereIn('parent_id', $parents)->with(['assignee', 'status'])->orderBy('position')->orderBy('id')->get()
                );
            }

            $tasks = $tasks->concat($level);
            $frontier = $level->pluck('id')->all();
        }

        return $tasks;
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
        $ids = [];
        $queue = [$this->task->getKey()];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($this->childrenMap->get($current, []) as $child) {
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
     * What the pickers for parent task and dependencies offer: what is already chosen plus the first matches of
     * the search, so a project with thousands of tasks does not send them all to the browser.
     *
     * @param  list<int|string>  $selectedIds
     * @param  list<int>  $excludedIds
     * @return Collection<int, Task>
     */
    private function pickerTasks(string $search, array $selectedIds, array $excludedIds, bool $withoutHeadings = false): Collection
    {
        $tasks = fn () => $this->task->project->tasks()
            ->whereNotIn('id', $excludedIds)
            ->when($withoutHeadings, fn ($query) => $query->where('is_section', false));
        $search = trim($search);

        $chosen = $selectedIds === [] ? new Collection : $tasks()->whereKey($selectedIds)->get(['id', 'title']);
        $matches = $tasks()
            ->when($search !== '', fn ($query) => $query->whereLike('title', '%'.$search.'%'))
            ->orderBy('title')
            ->limit(self::PICKER_LIMIT)
            ->get(['id', 'title']);

        return $chosen->concat($matches)->unique('id')->values();
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
        return $this->pickerTasks($this->parentSearch, $this->parentId === '' ? [] : [$this->parentId], $this->parentExclusions(), withoutHeadings: true);
    }

    #[Computed]
    public function blockerOptions(): Collection
    {
        return $this->pickerTasks($this->blockerSearch, $this->blockerIds, [$this->task->getKey()]);
    }

    #[Computed]
    public function blockingOptions(): Collection
    {
        return $this->pickerTasks($this->blockingSearch, $this->blockingIds, [$this->task->getKey()]);
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
     * Lets the list or board next to the side panel refresh itself.
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
            'position' => ($this->task->project->tasks()->where('parent_id', $parentId)->max('position') ?? -1) + 1,
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

            return;
        }

        $this->task->project->tasks()->whereKey($sectionId)->update(['title' => $title]);
        $this->resetSubtaskCaches();
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

        $subtree = [$itemId];
        $queue = [$itemId];

        while ($queue !== []) {
            foreach ($this->childrenMap->get(array_shift($queue), []) as $child) {
                $subtree[] = $child->id;
                $queue[] = $child->id;
            }
        }

        abort_if(in_array($parentId, $subtree, true), 422);

        $this->task->project->tasks()->findOrFail($parentId)
            ->placeChild($this->task->project->tasks()->findOrFail($itemId), $position);

        $this->resetSubtaskCaches();
    }

    /**
     * The people offered after @; tasks are looked up while typing (mentionTasks).
     *
     * @return array{users: list<array{id: int, name: string}>, searchTasks: bool}
     */
    #[Computed]
    public function mentionOptions(): array
    {
        return [
            'users' => $this->users->filter(fn ($user) => $user->isActive())->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()->all(),
            'searchTasks' => true,
        ];
    }

    /**
     * Tasks of this project whose title contains the typed text, newest first.
     *
     * @return list<array{id: int, title: string}>
     */
    #[Renderless]
    public function mentionTasks(string $query): array
    {
        $query = mb_substr(trim($query), 0, 50);

        return $this->task->project->tasks()
            ->where('is_section', false)
            ->when($query !== '', fn ($tasks) => $tasks->whereLike('title', '%'.addcslashes($query, '%_\\').'%'))
            ->orderByDesc('id')
            ->limit(self::MENTION_LIMIT)
            ->get(['id', 'title'])
            ->map(fn (Task $task) => ['id' => $task->id, 'title' => $task->title])
            ->all();
    }

    #[Renderless]
    public function previewMarkdown(string $text): string
    {
        return (string) MarkdownService::render(mb_substr($text, 0, 10000));
    }

    #[Computed]
    public function projectTags(): Collection
    {
        return $this->task->project->tags()->orderBy('name')->get();
    }

    public function createTag(): void
    {
        $this->authorizeEdit();

        $validated = $this->validate([
            'newTag' => ['required', 'string', 'max:50'],
        ]);

        $project = $this->task->project;
        $name = trim($validated['newTag']);

        $tag = $project->tags()->firstOrCreate(['name' => $name], [
            'color' => Color::next($project->tags()->count()),
        ]);

        $this->tagIds = array_values(array_unique([...$this->tagIds, (string) $tag->id]));
        $this->reset('newTag');
        unset($this->projectTags);
    }

    #[Computed]
    public function users(): Collection
    {
        $ids = $this->task->project->eligibleUsers()->pluck('users.id')
            ->merge($this->task->collaborators()->pluck('users.id'))
            ->push($this->task->assignee_id)
            ->filter()
            ->unique();

        return User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'deactivated_at']);
    }

    public function save(): void
    {
        $this->authorizeEdit();

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
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

        $parentChanged = ($validated['parentId'] ?: null) !== $this->task->parent_id;

        $this->task->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?: null,
            'status_id' => $validated['statusId'],
            'parent_id' => $validated['parentId'] ?: null,
            'position' => $parentChanged
                ? ($this->task->project->tasks()->where('parent_id', $validated['parentId'] ?: null)->max('position') ?? -1) + 1
                : $this->task->position,
            'assignee_id' => $validated['assigneeId'] ?: null,
            'due_date' => $validated['dueDate'] ?: null,
            'start_date' => $validated['startDate'] ?: null,
            'repeat_unit' => $validated['repeatUnit'] ?: null,
            'repeat_interval' => (int) $validated['repeatInterval'],
            'repeat_mode' => $validated['repeatMode'],
            'repeat_until' => ($validated['repeatUnit'] ?: null) ? ($validated['repeatUntil'] ?: null) : null,
        ]);

        $this->saveFieldValues($validated['fieldValues'] ?? []);

        $tagChanges = $this->task->tags()->sync($validated['tagIds']);
        $collaboratorChanges = $this->task->collaborators()->sync(
            array_values(array_diff($validated['collaboratorIds'], [(string) $validated['assigneeId']]))
        );

        $this->logSyncChanges('tags', $tagChanges, Tag::class, 'name');
        $this->logSyncChanges('collaborators', $collaboratorChanges, User::class, 'name');
        $this->logSyncChanges('blockers', $dependencyChanges['blockers'], Task::class, 'title');
        $this->logSyncChanges('blocking', $dependencyChanges['blocking'], Task::class, 'title');
        unset($this->activityFeed);

        $this->announceChange();

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /**
     * @param  array{attached: array<int, int|string>, detached: array<int, int|string>, updated: array<int, int|string>}  $changes
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function logSyncChanges(string $kind, array $changes, string $model, string $nameColumn): void
    {
        foreach (['attached' => 'added', 'detached' => 'removed'] as $key => $suffix) {
            if ($changes[$key] === []) {
                continue;
            }

            $this->task->logActivity("{$kind}_{$suffix}", [
                'names' => $model::whereIn('id', $changes[$key])->orderBy($nameColumn)->pluck($nameColumn)->all(),
            ]);
        }
    }

    /**
     * The latest comments and recorded changes (feedLimit of them), oldest first.
     *
     * @return \Illuminate\Support\Collection<int, array{at: \Illuminate\Support\Carbon, comment: ?\App\Models\Comment, activity: ?\App\Models\TaskActivity}>
     */
    #[Computed]
    public function activityFeed(): SupportCollection
    {
        $comments = $this->task->comments()->with('user')->latest()->latest('id')->limit($this->feedLimit)->get()
            ->map(fn ($comment) => ['at' => $comment->created_at, 'comment' => $comment, 'activity' => null]);

        $activities = $this->task->activities()->with('user')->latest()->latest('id')->limit($this->feedLimit)->get()
            ->map(fn ($activity) => ['at' => $activity->created_at, 'comment' => null, 'activity' => $activity]);

        return $comments->concat($activities)->sortByDesc('at')->take($this->feedLimit)->reverse()->values();
    }

    /**
     * Whether there are older entries than the ones shown.
     */
    #[Computed]
    public function hasEarlierFeed(): bool
    {
        return $this->task->comments()->count() + $this->task->activities()->count() > $this->feedLimit;
    }

    public function showEarlierFeed(): void
    {
        $this->feedLimit += self::FEED_PAGE;
        unset($this->activityFeed, $this->hasEarlierFeed);
    }

    public function addComment(): void
    {
        $this->authorizeEdit();

        $validated = $this->validate(['comment' => ['required', 'string', 'max:5000']]);

        $this->task->comments()->create([
            'user_id' => auth()->id(),
            'body' => $validated['comment'],
        ]);

        $this->reset('comment');
        unset($this->activityFeed);
    }

    public ?int $editingCommentId = null;

    public string $editingBody = '';

    private function commentOrFail(int $commentId): \App\Models\Comment
    {
        return $this->task->comments()->findOrFail($commentId);
    }

    /**
     * Only the author changes the words; the author and project admins may delete a comment.
     */
    public function startEditComment(int $commentId): void
    {
        $comment = $this->commentOrFail($commentId);

        abort_unless($comment->user_id === auth()->id() && $this->canEdit, 403);

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

        abort_unless($comment->user_id === auth()->id(), 403);

        $validated = $this->validate(['editingBody' => ['required', 'string', 'max:5000']], attributes: ['editingBody' => __('Comment')]);

        $comment->update(['body' => $validated['editingBody']]);

        $this->reset('editingCommentId', 'editingBody');
        unset($this->activityFeed);
    }

    public function deleteComment(int $commentId): void
    {
        $comment = $this->commentOrFail($commentId);

        abort_unless(
            ($comment->user_id === auth()->id() && $this->canEdit) || Gate::allows('manage', $this->task->project),
            403,
        );

        $comment->delete();

        if ($this->editingCommentId === $commentId) {
            $this->reset('editingCommentId', 'editingBody');
        }

        unset($this->activityFeed);
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
            $name = $upload->getClientOriginalName();

            $this->task->attachments()->create([
                'user_id' => auth()->id(),
                'name' => $name,
                'path' => $upload->store("attachments/{$this->task->project_id}/{$this->task->id}"),
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
            ]);

            $names[] = $name;
        }

        $this->reset('uploads');
        $this->task->logActivity('attachments_added', ['names' => $names]);
        unset($this->attachments, $this->activityFeed);
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

        $upload = $this->inlineUpload;
        $name = $upload->getClientOriginalName();

        $attachment = $this->task->attachments()->create([
            'user_id' => auth()->id(),
            'name' => $name,
            'path' => $upload->store("attachments/{$this->task->project_id}/{$this->task->id}"),
            'mime_type' => $upload->getMimeType(),
            'size' => $upload->getSize(),
        ]);

        $this->reset('inlineUpload');
        $this->task->logActivity('attachments_added', ['names' => [$name]]);
        unset($this->attachments, $this->activityFeed);

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

        $this->task->logActivity('attachments_removed', ['names' => [$attachment->name]]);
        unset($this->attachments, $this->activityFeed);
    }

    public function duplicate(): void
    {
        $this->authorizeEdit();

        abort_if($this->task->is_section, 404);

        $copy = $this->task->duplicate();
        unset($this->activityFeed);

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
    }

    public function presenceChannel(): string
    {
        return "task.{$this->task->getKey()}.presence";
    }

    public function rendering(View $view): void
    {
        if (! $this->panel) {
            $view->title($this->task->title);
        }
    }
};
?>

<div @class(['max-w-3xl' => ! $panel])>
    <x-presence :channel="$this->presenceChannel()" :one="__(':name is looking at this task too')" :many="__(':count people are looking at this task too')" class="mb-3" />

    @if ($changedElsewhere)
        <flux:callout class="mb-4" variant="warning" icon="arrow-path" :heading="__('Changed by someone else')" :text="__('This task was saved by another person. Reload to see their changes; what you typed is kept until then.')">
            <x-slot name="actions">
                <flux:button size="sm" wire:click="reloadFromServer">{{ __('Reload') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    @if ($panel)
        <div class="mb-4 flex items-center gap-2">
            <flux:text size="sm" class="min-w-0 flex-1 truncate">
                {{ $task->project->name }}
                @foreach ($this->ancestors as $ancestor)
                    <span class="text-zinc-400">/</span>
                    <button type="button" wire:key="ancestor-{{ $ancestor->id }}" x-on:click="$dispatch('open-task', { id: {{ $ancestor->id }} })" class="hover:underline">{{ $ancestor->title }}</button>
                @endforeach
            </flux:text>
            <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square" href="{{ route('tasks.show', $task) }}" wire:navigate aria-label="{{ __('Open as page') }}" title="{{ __('Open as page') }}" />
            <flux:button size="sm" variant="ghost" icon="x-mark" x-on:click="$dispatch('close-task')" aria-label="{{ __('Close') }}" title="{{ __('Close') }}" />
        </div>
    @else
        <flux:breadcrumbs class="mb-4">
            <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>{{ __('Projects') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item href="{{ route('projects.show', $task->project_id) }}" wire:navigate>{{ $task->project->name }}</flux:breadcrumbs.item>
            @foreach ($this->ancestors as $ancestor)
                <flux:breadcrumbs.item href="{{ route('tasks.show', $ancestor) }}" wire:navigate>{{ $ancestor->title }}</flux:breadcrumbs.item>
            @endforeach
            <flux:breadcrumbs.item>{{ __('Task') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
    @endif

    @unless ($this->canEdit)
        @if ($task->project->archived_at)
            <flux:callout class="mb-4" icon="archive-box" :heading="__('Archived')" :text="__('This project is archived and read-only.')" />
        @else
            <flux:callout class="mb-4" icon="eye" :heading="__('View only')" :text="__('In this project you can read tasks but not change them.')" />
        @endif
    @endunless

    <form wire:submit="save" class="space-y-4">
        <flux:input wire:model="title" :label="__('Title')" />
        <x-markdown-editor wire:model="description" :label="__('Description')" :rows="3" :mentions="$this->mentionOptions" :images="$this->canEdit ? $this->imageAttachments : null" />

        <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 px-3 dark:divide-zinc-800 dark:border-zinc-700">
            <x-task-field :label="__('Status')">
                <flux:select size="sm" variant="listbox" wire:model="statusId" :aria-label="__('Status')">
                    @foreach ($task->project->statuses as $status)
                        <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </x-task-field>

            <x-task-field :label="__('Assignee')">
                <flux:select size="sm" variant="listbox" wire:model="assigneeId" :aria-label="__('Assignee')">
                    <flux:select.option value="">{{ __('Nobody') }}</flux:select.option>
                    @foreach ($this->users as $user)
                        <flux:select.option value="{{ $user->id }}">{{ $user->name }}{{ $user->isActive() ? '' : ' ('.__('deactivated').')' }}</flux:select.option>
                    @endforeach
                </flux:select>
            </x-task-field>

            <x-task-field :label="__('Dates')">
                <div class="grid gap-2 sm:grid-cols-2">
                    <flux:date-picker size="sm" wire:model="dueDate" :aria-label="__('Due on')" :placeholder="__('Due on')" locale="{{ app()->getLocale() }}" clearable />
                    <flux:date-picker size="sm" wire:model="startDate" :aria-label="__('Starts on')" :placeholder="__('Starts on')" locale="{{ app()->getLocale() }}" clearable />
                </div>
            </x-task-field>

            <x-task-field :label="__('Recurrence')">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:select size="sm" variant="listbox" wire:model.live="repeatUnit" :aria-label="__('Repeat')" placeholder="{{ __('Never') }}" class="!w-36">
                        <flux:select.option value="">{{ __('Never') }}</flux:select.option>
                        @foreach (\App\Enums\RepeatUnit::cases() as $unit)
                            <flux:select.option value="{{ $unit->value }}">{{ $unit->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($repeatUnit !== '')
                        <flux:input size="sm" wire:model="repeatInterval" type="number" min="1" max="365" :aria-label="__('Every')" :placeholder="__('Every')" class="!w-20" />
                        <flux:select size="sm" variant="listbox" wire:model="repeatMode" :aria-label="__('Calculated')" class="!w-44">
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
                <x-task-field :label="$field->name" wire:key="field-{{ $field->id }}">
                    @switch($field->type)
                        @case(\App\Enums\CustomFieldType::Select)
                            <flux:select size="sm" variant="listbox" wire:model="fieldValues.{{ $field->id }}" :aria-label="$field->name" placeholder="–" clearable>
                                @foreach ($field->options as $option)
                                    <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @break
                        @case(\App\Enums\CustomFieldType::Number)
                            <flux:input size="sm" wire:model="fieldValues.{{ $field->id }}" type="number" step="any" :aria-label="$field->name" />
                            @break
                        @case(\App\Enums\CustomFieldType::Date)
                            <flux:date-picker size="sm" wire:model="fieldValues.{{ $field->id }}" :aria-label="$field->name" locale="{{ app()->getLocale() }}" :placeholder="__('Select a date')" clearable />
                            @break
                        @default
                            <flux:input size="sm" wire:model="fieldValues.{{ $field->id }}" :aria-label="$field->name" />
                    @endswitch
                </x-task-field>
            @endforeach

            <x-task-field :label="__('Parent task')">
                <flux:select size="sm" variant="listbox" wire:model="parentId" searchable :aria-label="__('Parent task')">
                    <x-slot name="search">
                        <flux:select.search wire:model.live.debounce.300ms="parentSearch" :placeholder="__('Search tasks …')" />
                    </x-slot>
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->parentOptions as $candidate)
                        <flux:select.option value="{{ $candidate->id }}">{{ $candidate->title }}</flux:select.option>
                    @endforeach
                </flux:select>
            </x-task-field>

            <x-task-field :label="__('Collaborators')">
                <flux:pillbox size="sm" wire:model="collaboratorIds" multiple searchable :aria-label="__('Collaborators')" :placeholder="__('Choose more people …')">
                    @foreach ($this->users as $user)
                        <flux:pillbox.option wire:key="collaborator-{{ $user->id }}" value="{{ $user->id }}">{{ $user->name }}{{ $user->isActive() ? '' : ' ('.__('deactivated').')' }}</flux:pillbox.option>
                    @endforeach
                </flux:pillbox>
            </x-task-field>

            <x-task-field :label="__('Tags')">
                <flux:pillbox size="sm" wire:model="tagIds" multiple :aria-label="__('Tags')" :placeholder="__('Choose tags …')">
                    @foreach ($this->projectTags as $tag)
                        <flux:pillbox.option wire:key="tag-{{ $tag->id }}" value="{{ $tag->id }}">{{ $tag->name }}</flux:pillbox.option>
                    @endforeach
                </flux:pillbox>
            </x-task-field>

            <x-task-field :label="__('Blocked by')">
                <flux:pillbox size="sm" wire:model="blockerIds" multiple searchable :aria-label="__('Blocked by')" :placeholder="__('Choose tasks …')">
                    <x-slot name="search">
                        <flux:pillbox.search wire:model.live.debounce.300ms="blockerSearch" :placeholder="__('Search tasks …')" />
                    </x-slot>
                    @foreach ($this->blockerOptions as $other)
                        <flux:pillbox.option wire:key="blocker-{{ $other->id }}" value="{{ $other->id }}">{{ $other->title }}</flux:pillbox.option>
                    @endforeach
                </flux:pillbox>
            </x-task-field>

            <x-task-field :label="__('Blocking')">
                <flux:pillbox size="sm" wire:model="blockingIds" multiple searchable :aria-label="__('Blocking')" :placeholder="__('Choose tasks …')">
                    <x-slot name="search">
                        <flux:pillbox.search wire:model.live.debounce.300ms="blockingSearch" :placeholder="__('Search tasks …')" />
                    </x-slot>
                    @foreach ($this->blockingOptions as $other)
                        <flux:pillbox.option wire:key="blocking-{{ $other->id }}" value="{{ $other->id }}">{{ $other->title }}</flux:pillbox.option>
                    @endforeach
                </flux:pillbox>
            </x-task-field>

            <x-task-field :label="__('Emails about this task')">
                <flux:switch wire:model.live="notificationsOn" :aria-label="__('Emails about this task')" />
                <flux:text size="sm" class="mt-1">{{ __('Assignees and collaborators get an email for new comments and status changes.') }}</flux:text>
            </x-task-field>
        </div>

        @if ($task->isBlocked())
            <flux:callout variant="warning" icon="lock-closed" :heading="__('This task is blocked')" :text="__('At least one task it depends on is not done yet.')" />
        @endif

        @if ($this->canEdit)
            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button type="button" icon="document-duplicate" wire:click="duplicate">{{ __('Duplicate') }}</flux:button>
                <flux:spacer />
                <flux:modal.trigger name="delete-task">
                    <flux:button variant="danger" icon="trash">{{ __('Delete') }}</flux:button>
                </flux:modal.trigger>
            </div>
        @endif
    </form>

    @if ($this->canEdit)
        <form wire:submit="createTag" class="mt-3 flex items-center gap-2">
            <flux:input size="sm" :aria-label="__('New tag')" :placeholder="__('New tag')" wire:model="newTag" class="max-w-xs" />
            <flux:button size="sm" type="submit" icon="plus">{{ __('Create') }}</flux:button>
        </form>
    @endif

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
        <form wire:submit="addSubtask({{ $task->id }})" class="mt-3 flex items-center gap-2">
            <flux:input size="sm" wire:model="newSubtaskTitles.{{ $task->id }}" :aria-label="__('New subtask')" :placeholder="__('New subtask')" class="max-w-sm" />
            <flux:button size="sm" type="submit" icon="plus">{{ __('Add') }}</flux:button>
            <flux:button size="sm" type="button" icon="bars-3-bottom-left" wire:click="addSection({{ $task->id }})">{{ __('Heading') }}</flux:button>
        </form>
        @error('newSubtaskTitles.'.$task->id) <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror
    @endif

    <flux:separator class="my-6" />

    <flux:heading size="lg" class="mb-2">{{ __('Attachments') }}</flux:heading>

    <div>
        <ul class="space-y-3">
            @foreach ($this->attachments as $attachment)
                @php($kind = $attachment->previewKind())
                @php($inlineUrl = route('attachments.show', [$attachment, 'inline' => 1]))
                <li wire:key="attachment-{{ $attachment->id }}">
                    <div class="flex items-center gap-3">
                        <flux:icon.paper-clip class="size-5 shrink-0 text-zinc-400" />
                        <div class="min-w-0 flex-1">
                            @if ($kind)
                                <button type="button" class="block max-w-full truncate text-left font-medium hover:underline" x-on:click="$dispatch('preview-file', @js(['url' => $inlineUrl, 'download' => route('attachments.show', $attachment), 'name' => $attachment->name, 'kind' => $kind]))">{{ $attachment->name }}</button>
                            @else
                                <a href="{{ route('attachments.show', $attachment) }}" class="block truncate font-medium hover:underline">{{ $attachment->name }}</a>
                            @endif
                            <flux:text size="sm">{{ $attachment->humanSize() }} · {{ $attachment->user?->name ?? __('Someone') }} · {{ $attachment->created_at->isoFormat('L LT') }}</flux:text>
                        </div>
                        @if ($kind && $kind !== 'image')
                            <flux:button size="sm" variant="ghost" icon="eye" inset x-on:click="$dispatch('preview-file', @js(['url' => $inlineUrl, 'download' => route('attachments.show', $attachment), 'name' => $attachment->name, 'kind' => $kind]))" aria-label="{{ __('Preview') }}" />
                        @endif
                        <flux:button size="sm" variant="ghost" icon="arrow-down-tray" inset href="{{ route('attachments.show', $attachment) }}" aria-label="{{ __('Download') }}" />
                        @if ($this->canEdit)
                            <flux:button size="sm" variant="ghost" icon="trash" inset wire:click="deleteAttachment({{ $attachment->id }})" wire:confirm="{{ __('Delete attachment “:name”?', ['name' => $attachment->name]) }}" aria-label="{{ __('Delete attachment') }}" />
                        @endif
                    </div>
                    @if ($kind === 'image')
                        <button type="button" class="mt-2 ml-8 block" x-on:click="$dispatch('preview-file', @js(['url' => $inlineUrl, 'download' => route('attachments.show', $attachment), 'name' => $attachment->name, 'kind' => $kind]))" aria-label="{{ __('Preview') }}">
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
            @error('uploads') <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror
            @error('uploads.*') <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror
        </div>
    @endif

    <flux:separator class="my-6" />

    <flux:heading size="lg" class="mb-4">{{ __('Activity and comments') }}</flux:heading>

    <div class="space-y-3">
        @if ($this->hasEarlierFeed)
            <flux:button size="sm" variant="ghost" icon="chevron-up" wire:click="showEarlierFeed">{{ __('Show earlier entries') }}</flux:button>
        @endif

        @forelse ($this->activityFeed as $entry)
            @if ($entry['comment'])
                @php($comment = $entry['comment'])
                <flux:card wire:key="comment-{{ $comment->id }}" class="space-y-1">
                    <div class="flex items-center gap-2">
                        <flux:text class="min-w-0 flex-1 text-sm"><strong>{{ $comment->user->name }}</strong> · {{ $comment->created_at->isoFormat('L LT') }}@if ($comment->wasEdited()) · {{ __('edited') }} @endif</flux:text>
                        @if ($comment->user_id === auth()->id() && $this->canEdit && $editingCommentId !== $comment->id)
                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="startEditComment({{ $comment->id }})" aria-label="{{ __('Edit comment') }}" />
                        @endif
                        @if (($comment->user_id === auth()->id() && $this->canEdit) || $this->canManage)
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteComment({{ $comment->id }})" wire:confirm="{{ __('Delete comment?') }}" aria-label="{{ __('Delete comment') }}" />
                        @endif
                    </div>

                    @if ($editingCommentId === $comment->id)
                        <form wire:submit="saveComment" class="space-y-2">
                            <x-markdown-editor wire:model="editingBody" :rows="3" :mentions="$this->mentionOptions" :images="$this->canEdit ? $this->imageAttachments : null" />
                            @error('editingBody') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
                            <div class="flex gap-2">
                                <flux:button size="sm" type="submit" variant="primary">{{ __('Save') }}</flux:button>
                                <flux:button size="sm" type="button" variant="ghost" wire:click="cancelEditComment">{{ __('Cancel') }}</flux:button>
                            </div>
                        </form>
                    @else
                        <x-markdown :text="$comment->body" />
                    @endif
                </flux:card>
            @else
                @php($activity = $entry['activity'])
                <flux:text wire:key="activity-{{ $activity->id }}" size="sm" class="px-1">
                    <strong>{{ $activity->user?->name ?? __('Someone') }}</strong> {{ $activity->sentence() }} · {{ $activity->created_at->isoFormat('L LT') }}
                </flux:text>
            @endif
        @empty
            <flux:text>{{ __('No activity yet.') }}</flux:text>
        @endforelse
    </div>

    @if ($this->canEdit)
        <form wire:submit="addComment" class="mt-6 space-y-3">
            <x-markdown-editor wire:model="comment" :placeholder="__('Write a comment … (Markdown, @ for mentions)')" :rows="3" :mentions="$this->mentionOptions" :images="$this->canEdit ? $this->imageAttachments : null" />
            <flux:button type="submit">{{ __('Post comment') }}</flux:button>
        </form>
    @endif

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
