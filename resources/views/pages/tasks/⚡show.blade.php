<?php

use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\TaskStatus;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Task $task;

    public string $title = '';

    public string $description = '';

    public string $status = '';

    public string $assigneeId = '';

    public string $dueDate = '';

    public string $comment = '';

    /** @var list<string> */
    public array $tagIds = [];

    public string $newTag = '';

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

    public function mount(): void
    {
        $this->title = $this->task->title;
        $this->description = $this->task->description ?? '';
        $this->status = $this->task->status->value;
        $this->assigneeId = (string) ($this->task->assignee_id ?? '');
        $this->dueDate = $this->task->due_date?->format('Y-m-d') ?? '';
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

    #[Computed]
    public function projectTasks()
    {
        return $this->task->project->tasks()
            ->with('assignee')
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
    }

    public function addSubtask(int $parentId): void
    {
        $this->createChild($parentId, isSection: false);
    }

    public function addSection(int $parentId): void
    {
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
        abort_unless(in_array($subtaskId, $this->descendantIds, true) && ! in_array($subtaskId, $this->sectionIds, true), 404);

        $subtask = $this->task->project->tasks()->findOrFail($subtaskId);
        $subtask->update([
            'status' => $subtask->status === TaskStatus::Done ? TaskStatus::Todo : TaskStatus::Done,
        ]);

        $this->resetSubtaskCaches();
    }

    public function updatedSectionTitles(string $value, string $sectionId): void
    {
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
        abort_unless(in_array($sectionId, $this->sectionIds, true) && in_array($sectionId, $this->descendantIds, true), 404);

        $this->task->project->tasks()->whereKey($sectionId)->delete();
        unset($this->sectionTitles[$sectionId]);
        $this->resetSubtaskCaches();
    }

    public function moveSubtask(int|string $itemId, int $position, int|string $parentId): void
    {
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
        $validated = $this->validate([
            'newTag' => ['required', 'string', 'max:50'],
        ]);

        $project = $this->task->project;
        $name = trim($validated['newTag']);

        $tag = $project->tags()->firstOrCreate(['name' => $name], [
            'color' => Tag::COLORS[$project->tags()->count() % count(Tag::COLORS)],
        ]);

        $this->tagIds = array_values(array_unique([...$this->tagIds, (string) $tag->id]));
        $this->reset('newTag');
        unset($this->projectTags);
    }

    #[Computed]
    public function users()
    {
        return User::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function comments()
    {
        return $this->task->comments()->with('user')->oldest()->get();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', 'in:'.implode(',', array_column(TaskStatus::cases(), 'value'))],
            'assigneeId' => ['nullable', 'exists:users,id'],
            'dueDate' => ['nullable', 'date'],
            'tagIds' => ['array'],
            'tagIds.*' => ['integer', Rule::exists('tags', 'id')->where('project_id', $this->task->project_id)],
            'parentId' => ['nullable', Rule::in($this->possibleParents->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'collaboratorIds' => ['array'],
            'collaboratorIds.*' => ['integer', 'exists:users,id'],
            'blockerIds' => ['array'],
            'blockerIds.*' => ['integer', Rule::exists('tasks', 'id')->where('project_id', $this->task->project_id)->whereNot('id', $this->task->getKey())],
            'blockingIds' => ['array'],
            'blockingIds.*' => ['integer', Rule::exists('tasks', 'id')->where('project_id', $this->task->project_id)->whereNot('id', $this->task->getKey())],
        ]);

        if (array_intersect($validated['blockerIds'], $validated['blockingIds']) !== []) {
            throw ValidationException::withMessages([
                'blockingIds' => 'Eine Aufgabe kann nicht gleichzeitig blockieren und blockiert werden.',
            ]);
        }

        DB::transaction(function () use ($validated) {
            $this->task->blockers()->sync($validated['blockerIds']);
            $this->task->blocking()->sync($validated['blockingIds']);

            if ($this->task->hasDependencyCycle()) {
                throw ValidationException::withMessages([
                    'blockingIds' => 'Diese Abhängigkeiten würden einen Kreis bilden.',
                ]);
            }
        });

        $parentChanged = ($validated['parentId'] ?: null) !== $this->task->parent_id;

        $this->task->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?: null,
            'status' => $validated['status'],
            'parent_id' => $validated['parentId'] ?: null,
            'position' => $parentChanged
                ? ($this->task->project->tasks()->where('parent_id', $validated['parentId'] ?: null)->max('position') ?? -1) + 1
                : $this->task->position,
            'assignee_id' => $validated['assigneeId'] ?: null,
            'due_date' => $validated['dueDate'] ?: null,
        ]);

        $this->task->tags()->sync($validated['tagIds']);
        $this->task->collaborators()->sync(
            array_values(array_diff($validated['collaboratorIds'], [(string) $validated['assigneeId']]))
        );

        Flux::toast(variant: 'success', text: 'Gespeichert.');
    }

    public function addComment(): void
    {
        $validated = $this->validate(['comment' => ['required', 'string', 'max:5000']]);

        $this->task->comments()->create([
            'user_id' => auth()->id(),
            'body' => $validated['comment'],
        ]);

        $this->reset('comment');
        unset($this->comments);
    }

    public function delete(): void
    {
        $projectId = $this->task->project_id;
        $this->task->delete();

        $this->redirectRoute('projects.show', $projectId, navigate: true);
    }

    public function rendering($view): void
    {
        $view->title($this->task->title);
    }
};
?>

<div class="max-w-3xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>Projekte</flux:breadcrumbs.item>
        <flux:breadcrumbs.item href="{{ route('projects.show', $task->project_id) }}" wire:navigate>{{ $task->project->name }}</flux:breadcrumbs.item>
        @foreach ($this->ancestors as $ancestor)
            <flux:breadcrumbs.item href="{{ route('tasks.show', $ancestor) }}" wire:navigate>{{ $ancestor->title }}</flux:breadcrumbs.item>
        @endforeach
        <flux:breadcrumbs.item>Aufgabe</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="title" label="Titel" />
        <flux:textarea wire:model="description" label="Beschreibung" rows="5" />

        <div class="grid gap-4 sm:grid-cols-3">
            <flux:select variant="listbox" wire:model="status" label="Status">
                @foreach (TaskStatus::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select variant="listbox" wire:model="assigneeId" label="Zuständig">
                <flux:select.option value="">Niemand</flux:select.option>
                @foreach ($this->users as $user)
                    <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:date-picker wire:model="dueDate" label="Fällig am" locale="de-DE" clearable />
        </div>

        <flux:select variant="listbox" wire:model="parentId" label="Übergeordnete Aufgabe">
            <flux:select.option value="">Keine</flux:select.option>
            @foreach ($this->possibleParents as $candidate)
                <flux:select.option value="{{ $candidate->id }}">{{ $candidate->title }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:pillbox wire:model="collaboratorIds" multiple searchable label="Beteiligte" placeholder="Weitere Personen wählen …">
            @foreach ($this->users as $user)
                <flux:pillbox.option wire:key="collaborator-{{ $user->id }}" value="{{ $user->id }}">{{ $user->name }}</flux:pillbox.option>
            @endforeach
        </flux:pillbox>

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

        <div class="flex gap-3">
            <flux:button type="submit" variant="primary">Speichern</flux:button>
            <flux:spacer />
            <flux:modal.trigger name="delete-task">
                <flux:button variant="danger" icon="trash">Löschen</flux:button>
            </flux:modal.trigger>
        </div>
    </form>

    <form wire:submit="createTag" class="mt-4 flex items-end gap-2">
        <flux:input wire:model="newTag" label="Neuer Tag" placeholder="z. B. Bug" class="max-w-xs" />
        <flux:button type="submit" icon="plus">Anlegen</flux:button>
    </form>

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
        <x-task-subtree :tasks="$rootChildren" :children-map="$this->childrenMap" :parent-id="$task->id" />
    @endif

    <form wire:submit="addSubtask({{ $task->id }})" class="mt-3 flex items-end gap-2">
        <flux:input wire:model="newSubtaskTitles.{{ $task->id }}" label="Neue Subtask" placeholder="Titel …" class="max-w-sm" />
        <flux:button type="submit" icon="plus">Hinzufügen</flux:button>
        <flux:button type="button" icon="bars-3-bottom-left" wire:click="addSection({{ $task->id }})">Überschrift</flux:button>
    </form>
    @error('newSubtaskTitles.'.$task->id) <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror

    <flux:separator class="my-8" />

    <flux:heading size="lg" class="mb-4">Kommentare</flux:heading>

    <div class="space-y-4">
        @forelse ($this->comments as $comment)
            <flux:card wire:key="comment-{{ $comment->id }}" class="space-y-1">
                <flux:text class="text-sm"><strong>{{ $comment->user->name }}</strong> · {{ $comment->created_at->format('d.m.Y H:i') }}</flux:text>
                <flux:text class="whitespace-pre-line">{{ $comment->body }}</flux:text>
            </flux:card>
        @empty
            <flux:text>Noch keine Kommentare.</flux:text>
        @endforelse
    </div>

    <form wire:submit="addComment" class="mt-6 space-y-3">
        <flux:textarea wire:model="comment" placeholder="Kommentar schreiben …" rows="3" />
        <flux:button type="submit">Kommentieren</flux:button>
    </form>

    <flux:modal name="delete-task" class="min-w-[22rem]">
        <div class="space-y-6">
            <flux:heading size="lg">Aufgabe löschen?</flux:heading>
            <flux:text>Die Aufgabe @if (count($this->descendantIds) - count($this->sectionIds) > 0) mit {{ count($this->descendantIds) - count($this->sectionIds) }} Subtasks @endif und alle Kommentare werden unwiderruflich gelöscht.</flux:text>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Abbrechen</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">Löschen</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
