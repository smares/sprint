<?php

use App\Color;
use App\CustomFieldType;
use App\Markdown;
use App\Models\Attachment;
use App\Models\CustomField;
use App\RepeatMode;
use App\RepeatUnit;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

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

    /** @var list<string> */
    public array $tagIds = [];

    public string $newTag = '';

    public bool $notificationsOn = true;

    public string $parentId = '';

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

    public function hydrate(): void
    {
        Gate::authorize('view', $this->task->project);
    }

    #[Computed]
    public function canEdit(): bool
    {
        return Gate::allows('edit', $this->task->project);
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
        $this->sectionTitles = $this->task->project->tasks()
            ->where('is_section', true)
            ->pluck('title', 'id')
            ->all();
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
    public function customFields()
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
            $field->type === CustomFieldType::Date => \Illuminate\Support\Carbon::parse($value)->format('d.m.Y'),
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

    #[Computed]
    public function projectTasks()
    {
        return $this->task->project->tasks()
            ->with(['assignee', 'status'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function childrenMap()
    {
        return $this->projectTasks->groupBy(fn ($task) => $task->parent_id ?? 0);
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
        $byId = $this->projectTasks->keyBy('id');
        $chain = [];
        $current = $byId->get($this->task->parent_id);

        while ($current !== null) {
            array_unshift($chain, $current);
            $current = $byId->get($current->parent_id);
        }

        return $chain;
    }

    /**
     * @return array{done: int, total: int}|null
     */
    #[Computed]
    public function progress(): ?array
    {
        return $this->task->project->subtaskProgress()[$this->task->getKey()] ?? null;
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function sectionIds(): array
    {
        return $this->projectTasks->where('is_section', true)->pluck('id')->all();
    }

    #[Computed]
    public function possibleParents()
    {
        $excluded = [$this->task->getKey(), ...$this->descendantIds];

        return $this->projectTasks->reject(fn ($task) => $task->is_section || in_array($task->id, $excluded, true));
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
        unset($this->projectTasks, $this->childrenMap, $this->descendantIds, $this->sectionIds, $this->progress);

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
        ], attributes: ["newSubtaskTitles.$parentId" => 'Titel']);

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
            $this->sectionTitles[$sectionId] = $this->projectTasks->firstWhere('id', (int) $sectionId)->title;

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
     * @return array{users: list<array{id: int, name: string}>, tasks: list<array{id: int, title: string}>}
     */
    #[Computed]
    public function mentionOptions(): array
    {
        return [
            'users' => $this->users->filter(fn ($user) => $user->isActive())->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()->all(),
            'tasks' => $this->projectTasks
                ->reject(fn ($task) => $task->is_section)
                ->sortByDesc('id')
                ->take(500)
                ->map(fn ($task) => ['id' => $task->id, 'title' => $task->title])
                ->values()
                ->all(),
        ];
    }

    public function previewMarkdown(string $text): string
    {
        return (string) Markdown::render(mb_substr($text, 0, 10000));
    }

    #[Computed]
    public function otherTasks()
    {
        return $this->task->project->tasks()
            ->whereKeyNot($this->task->getKey())
            ->orderBy('title')
            ->get(['id', 'title']);
    }

    #[Computed]
    public function projectTags()
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
    public function users()
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
            'parentId' => ['nullable', Rule::in($this->possibleParents->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'collaboratorIds' => ['array'],
            'collaboratorIds.*' => ['integer', Rule::in($this->users->pluck('id')->all())],
            'blockerIds' => ['array'],
            'blockerIds.*' => ['integer', Rule::exists('tasks', 'id')->where('project_id', $this->task->project_id)->whereNot('id', $this->task->getKey())],
            'blockingIds' => ['array'],
            'blockingIds.*' => ['integer', Rule::exists('tasks', 'id')->where('project_id', $this->task->project_id)->whereNot('id', $this->task->getKey())],
        ]);

        if ($validated['repeatUnit'] !== '' && $validated['repeatUnit'] !== null && ($validated['dueDate'] ?? '') === '') {
            throw ValidationException::withMessages([
                'dueDate' => 'Für eine wiederkehrende Aufgabe braucht es eine Fälligkeit.',
            ]);
        }

        if (array_intersect($validated['blockerIds'], $validated['blockingIds']) !== []) {
            throw ValidationException::withMessages([
                'blockingIds' => 'Eine Aufgabe kann nicht gleichzeitig blockieren und blockiert werden.',
            ]);
        }

        $dependencyChanges = DB::transaction(function () use ($validated) {
            $blockers = $this->task->blockers()->sync($validated['blockerIds']);
            $blocking = $this->task->blocking()->sync($validated['blockingIds']);

            if ($this->task->hasDependencyCycle()) {
                throw ValidationException::withMessages([
                    'blockingIds' => 'Diese Abhängigkeiten würden einen Kreis bilden.',
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

        Flux::toast(variant: 'success', text: 'Gespeichert.');
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
     * Comments and recorded changes, oldest first.
     *
     * @return \Illuminate\Support\Collection<int, array{at: \Illuminate\Support\Carbon, comment: ?\App\Models\Comment, activity: ?\App\Models\TaskActivity}>
     */
    #[Computed]
    public function activityFeed()
    {
        $comments = $this->task->comments()->with('user')->get()
            ->map(fn ($comment) => ['at' => $comment->created_at, 'comment' => $comment, 'activity' => null]);

        $activities = $this->task->activities()->with('user')->get()
            ->map(fn ($activity) => ['at' => $activity->created_at, 'comment' => null, 'activity' => $activity]);

        return $comments->concat($activities)->sortBy('at')->values();
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

    #[Computed]
    public function attachments()
    {
        return $this->task->attachments()->with('user')->orderBy('id')->get();
    }

    public function updatedUploads(): void
    {
        $this->authorizeEdit();

        $this->validate(['uploads' => ['array', 'max:20'], 'uploads.*' => ['file', 'max:'.Attachment::MAX_KILOBYTES]], [], ['uploads.*' => 'Datei']);

        $names = [];

        foreach ($this->uploads as $upload) {
            $name = $upload->getClientOriginalName();

            $this->task->attachments()->create([
                'user_id' => auth()->id(),
                'name' => $name,
                'path' => $upload->store("attachments/{$this->task->project_id}/{$this->task->id}", Attachment::DISK),
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
            ]);

            $names[] = $name;
        }

        $this->reset('uploads');
        $this->task->logActivity('attachments_added', ['names' => $names]);
        unset($this->attachments, $this->activityFeed);
    }

    public function deleteAttachment(int $attachmentId): void
    {
        $this->authorizeEdit();

        $attachment = $this->task->attachments()->findOrFail($attachmentId);
        $attachment->delete();

        $this->task->logActivity('attachments_removed', ['names' => [$attachment->name]]);
        unset($this->attachments, $this->activityFeed);
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

    public function rendering($view): void
    {
        if (! $this->panel) {
            $view->title($this->task->title);
        }
    }
};
?>

<div @class(['max-w-3xl' => ! $panel])>
    @if ($panel)
        <div class="mb-4 flex items-center gap-2">
            <flux:text size="sm" class="min-w-0 flex-1 truncate">
                {{ $task->project->name }}
                @foreach ($this->ancestors as $ancestor)
                    <span class="text-zinc-400">/</span>
                    <button type="button" wire:key="ancestor-{{ $ancestor->id }}" x-on:click="$dispatch('open-task', { id: {{ $ancestor->id }} })" class="hover:underline">{{ $ancestor->title }}</button>
                @endforeach
            </flux:text>
            <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square" href="{{ route('tasks.show', $task) }}" wire:navigate aria-label="Als Seite öffnen" title="Als Seite öffnen" />
            <flux:button size="sm" variant="ghost" icon="x-mark" x-on:click="$dispatch('close-task')" aria-label="Schließen" title="Schließen" />
        </div>
    @else
        <flux:breadcrumbs class="mb-4">
            <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>Projekte</flux:breadcrumbs.item>
            <flux:breadcrumbs.item href="{{ route('projects.show', $task->project_id) }}" wire:navigate>{{ $task->project->name }}</flux:breadcrumbs.item>
            @foreach ($this->ancestors as $ancestor)
                <flux:breadcrumbs.item href="{{ route('tasks.show', $ancestor) }}" wire:navigate>{{ $ancestor->title }}</flux:breadcrumbs.item>
            @endforeach
            <flux:breadcrumbs.item>Aufgabe</flux:breadcrumbs.item>
        </flux:breadcrumbs>
    @endif

    @unless ($this->canEdit)
        <flux:callout class="mb-4" icon="eye" heading="Nur ansehen" text="In diesem Projekt darfst du Aufgaben lesen, aber nicht ändern." />
    @endunless

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="title" label="Titel" />
        <x-markdown-editor wire:model="description" label="Beschreibung" :rows="5" :mentions="$this->mentionOptions" />

        <div @class(['grid gap-4', 'sm:grid-cols-3' => ! $panel, 'sm:grid-cols-2' => $panel])>
            <flux:select variant="listbox" wire:model="statusId" label="Status">
                @foreach ($task->project->statuses as $status)
                    <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select variant="listbox" wire:model="assigneeId" label="Zuständig">
                <flux:select.option value="">Niemand</flux:select.option>
                @foreach ($this->users as $user)
                    <flux:select.option value="{{ $user->id }}">{{ $user->name }}{{ $user->isActive() ? '' : ' (deaktiviert)' }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:date-picker wire:model="dueDate" label="Fällig am" locale="de-DE" placeholder="Datum wählen" clearable />
        </div>

        <div @class(['grid gap-4', 'sm:grid-cols-3' => ! $panel, 'sm:grid-cols-2' => $panel])>
            <flux:date-picker wire:model="startDate" label="Beginnt am" locale="de-DE" placeholder="Datum wählen" clearable />
        </div>

        <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading>Wiederholung</flux:heading>
            <div class="grid gap-4 sm:grid-cols-4">
                <flux:select variant="listbox" wire:model.live="repeatUnit" label="Wiederholen" placeholder="Nie">
                    <flux:select.option value="">Nie</flux:select.option>
                    @foreach (\App\RepeatUnit::cases() as $unit)
                        <flux:select.option value="{{ $unit->value }}">{{ $unit->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($repeatUnit !== '')
                    <flux:input wire:model="repeatInterval" type="number" min="1" max="365" label="Alle" />
                    <flux:select variant="listbox" wire:model="repeatMode" label="Berechnet">
                        @foreach (\App\RepeatMode::cases() as $mode)
                            <flux:select.option value="{{ $mode->value }}">{{ $mode->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:date-picker wire:model="repeatUntil" label="Bis" locale="de-DE" placeholder="Datum wählen" clearable />
                @endif
            </div>
            @if ($repeatUnit !== '')
                <flux:text size="sm">Sobald du die Aufgabe erledigst, entsteht die nächste (mit Zuständigen, Tags, Feldern und Subtasks). <em>Nach Plan</em> rechnet ab dem Fälligkeitsdatum, <em>nach Erledigung</em> ab dem Tag, an dem du sie erledigst. Voraussetzung ist eine Fälligkeit.</flux:text>
            @endif
        </div>

        @if ($this->customFields->isNotEmpty())
            <div @class(['grid gap-4', 'sm:grid-cols-3' => ! $panel, 'sm:grid-cols-2' => $panel])>
                @foreach ($this->customFields as $field)
                    @switch($field->type)
                        @case(\App\CustomFieldType::Select)
                            <flux:select wire:key="field-{{ $field->id }}" variant="listbox" wire:model="fieldValues.{{ $field->id }}" :label="$field->name" placeholder="–" clearable>
                                @foreach ($field->options as $option)
                                    <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @break
                        @case(\App\CustomFieldType::Number)
                            <flux:input wire:key="field-{{ $field->id }}" wire:model="fieldValues.{{ $field->id }}" type="number" step="any" :label="$field->name" />
                            @break
                        @case(\App\CustomFieldType::Date)
                            <flux:date-picker wire:key="field-{{ $field->id }}" wire:model="fieldValues.{{ $field->id }}" :label="$field->name" locale="de-DE" placeholder="Datum wählen" clearable />
                            @break
                        @default
                            <flux:input wire:key="field-{{ $field->id }}" wire:model="fieldValues.{{ $field->id }}" :label="$field->name" />
                    @endswitch
                @endforeach
            </div>
        @endif

        <flux:select variant="listbox" wire:model="parentId" label="Übergeordnete Aufgabe">
            <flux:select.option value="">Keine</flux:select.option>
            @foreach ($this->possibleParents as $candidate)
                <flux:select.option value="{{ $candidate->id }}">{{ $candidate->title }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:pillbox wire:model="collaboratorIds" multiple searchable label="Beteiligte" placeholder="Weitere Personen wählen …">
            @foreach ($this->users as $user)
                <flux:pillbox.option wire:key="collaborator-{{ $user->id }}" value="{{ $user->id }}">{{ $user->name }}{{ $user->isActive() ? '' : ' (deaktiviert)' }}</flux:pillbox.option>
            @endforeach
        </flux:pillbox>

        <flux:switch wire:model.live="notificationsOn" label="E-Mails zu dieser Aufgabe" description="Zuständige und Beteiligte bekommen eine Mail bei neuen Kommentaren und Statuswechseln." />

        <flux:pillbox wire:model="tagIds" multiple label="Tags" placeholder="Tags wählen …">
            @foreach ($this->projectTags as $tag)
                <flux:pillbox.option wire:key="tag-{{ $tag->id }}" value="{{ $tag->id }}">{{ $tag->name }}</flux:pillbox.option>
            @endforeach
        </flux:pillbox>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:pillbox wire:model="blockerIds" multiple searchable label="Blockiert von" placeholder="Aufgaben wählen …">
                @foreach ($this->otherTasks as $other)
                    <flux:pillbox.option wire:key="blocker-{{ $other->id }}" value="{{ $other->id }}">{{ $other->title }}</flux:pillbox.option>
                @endforeach
            </flux:pillbox>
            <flux:pillbox wire:model="blockingIds" multiple searchable label="Blockiert" placeholder="Aufgaben wählen …">
                @foreach ($this->otherTasks as $other)
                    <flux:pillbox.option wire:key="blocking-{{ $other->id }}" value="{{ $other->id }}">{{ $other->title }}</flux:pillbox.option>
                @endforeach
            </flux:pillbox>
        </div>

        @if ($task->isBlocked())
            <flux:callout variant="warning" icon="lock-closed" heading="Diese Aufgabe ist blockiert" text="Mindestens eine Aufgabe, von der sie abhängt, ist noch nicht erledigt." />
        @endif

        @if ($this->canEdit)
            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">Speichern</flux:button>
                <flux:spacer />
                <flux:modal.trigger name="delete-task">
                    <flux:button variant="danger" icon="trash">Löschen</flux:button>
                </flux:modal.trigger>
            </div>
        @endif
    </form>

    @if ($this->canEdit)
        <form wire:submit="createTag" class="mt-4 flex items-end gap-2">
            <flux:input wire:model="newTag" label="Neuer Tag" placeholder="z. B. Bug" class="max-w-xs" />
            <flux:button type="submit" icon="plus">Anlegen</flux:button>
        </form>
    @endif

    <flux:separator class="my-8" />

    <flux:heading size="lg" class="mb-2">Subtasks</flux:heading>

    @if ($this->progress)
        <div class="mb-4 max-w-sm">
            <flux:text size="sm" class="mb-1">{{ $this->progress['done'] }} von {{ $this->progress['total'] }} erledigt</flux:text>
            <flux:progress :value="intdiv($this->progress['done'] * 100, $this->progress['total'])" />
        </div>
    @endif

    @php($rootChildren = $this->childrenMap->get($task->id, collect()))
    @if ($rootChildren->isNotEmpty())
        <x-task-subtree :tasks="$rootChildren" :children-map="$this->childrenMap" :parent-id="$task->id" :can-edit="$this->canEdit" :panel="$panel" />
    @endif

    @if ($this->canEdit)
        <form wire:submit="addSubtask({{ $task->id }})" class="mt-3 flex items-end gap-2">
            <flux:input wire:model="newSubtaskTitles.{{ $task->id }}" label="Neue Subtask" placeholder="Titel …" class="max-w-sm" />
            <flux:button type="submit" icon="plus">Hinzufügen</flux:button>
            <flux:button type="button" icon="bars-3-bottom-left" wire:click="addSection({{ $task->id }})">Überschrift</flux:button>
        </form>
        @error('newSubtaskTitles.'.$task->id) <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror
    @endif

    <flux:separator class="my-8" />

    <flux:heading size="lg" class="mb-2">Anhänge</flux:heading>

    <ul class="space-y-2">
        @foreach ($this->attachments as $attachment)
            <li wire:key="attachment-{{ $attachment->id }}" class="flex items-center gap-3">
                @if ($attachment->isInlineImage())
                    <img src="{{ route('attachments.show', [$attachment, 'inline' => 1]) }}" alt="" class="size-10 rounded object-cover" loading="lazy">
                @else
                    <flux:icon.paper-clip class="size-5 text-zinc-400" />
                @endif
                <div class="min-w-0 flex-1">
                    <a href="{{ route('attachments.show', $attachment) }}" class="block truncate font-medium hover:underline">{{ $attachment->name }}</a>
                    <flux:text size="sm">{{ $attachment->humanSize() }} · {{ $attachment->user?->name ?? 'Jemand' }} · {{ $attachment->created_at->format('d.m.Y H:i') }}</flux:text>
                </div>
                @if ($this->canEdit)
                    <flux:button size="sm" variant="ghost" icon="trash" inset wire:click="deleteAttachment({{ $attachment->id }})" wire:confirm="Anhang „{{ $attachment->name }}“ löschen?" aria-label="Anhang löschen" />
                @endif
            </li>
        @endforeach
    </ul>

    @if ($this->attachments->isEmpty())
        <flux:text>Noch keine Anhänge.</flux:text>
    @endif

    @if ($this->canEdit)
        <div class="mt-3">
            <flux:input type="file" wire:model="uploads" multiple label="Dateien hinzufügen" description="Bis zu {{ intdiv(\App\Models\Attachment::MAX_KILOBYTES, 1024) }} MB pro Datei." />
            <div wire:loading wire:target="uploads"><flux:text size="sm">Wird hochgeladen …</flux:text></div>
            @error('uploads') <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror
            @error('uploads.*') <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror
        </div>
    @endif

    <flux:separator class="my-8" />

    <flux:heading size="lg" class="mb-4">Aktivität und Kommentare</flux:heading>

    <div class="space-y-3">
        @forelse ($this->activityFeed as $entry)
            @if ($entry['comment'])
                @php($comment = $entry['comment'])
                <flux:card wire:key="comment-{{ $comment->id }}" class="space-y-1">
                    <flux:text class="text-sm"><strong>{{ $comment->user->name }}</strong> · {{ $comment->created_at->format('d.m.Y H:i') }}</flux:text>
                    <x-markdown :text="$comment->body" />
                </flux:card>
            @else
                @php($activity = $entry['activity'])
                <flux:text wire:key="activity-{{ $activity->id }}" size="sm" class="px-1">
                    <strong>{{ $activity->user?->name ?? 'Jemand' }}</strong> {{ $activity->sentence() }} · {{ $activity->created_at->format('d.m.Y H:i') }}
                </flux:text>
            @endif
        @empty
            <flux:text>Noch keine Aktivität.</flux:text>
        @endforelse
    </div>

    @if ($this->canEdit)
        <form wire:submit="addComment" class="mt-6 space-y-3">
            <x-markdown-editor wire:model="comment" placeholder="Kommentar schreiben … (Markdown, @ für Erwähnungen)" :rows="3" :mentions="$this->mentionOptions" />
            <flux:button type="submit">Kommentieren</flux:button>
        </form>
    @endif

    <flux:modal name="delete-task" class="min-w-[22rem]">
        <div class="space-y-6">
            <flux:heading size="lg">Aufgabe löschen?</flux:heading>
            <flux:text>Die Aufgabe @if (count($this->descendantIds) - count($this->sectionIds) > 0) mit {{ count($this->descendantIds) - count($this->sectionIds) }} Subtasks @endif sowie alle Kommentare und Anhänge werden unwiderruflich gelöscht.</flux:text>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Abbrechen</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">Löschen</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
