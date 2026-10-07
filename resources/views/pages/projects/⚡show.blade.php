<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\TaskStatus;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public Project $project;

    #[Url(as: 'status')]
    public string $statusFilter = 'open';

    #[Url(as: 'assignee')]
    public string $assigneeFilter = '';

    #[Url(as: 'tag')]
    public string $tagFilter = '';

    public string $title = '';

    public string $description = '';

    public string $assigneeId = '';

    public string $dueDate = '';

    #[Computed]
    public function tasks()
    {
        return $this->project->tasks()
            ->with(['assignee', 'tags'])
            ->when($this->statusFilter === 'open', fn ($q) => $q->where('status', '!=', TaskStatus::Done))
            ->when(TaskStatus::tryFrom($this->statusFilter), fn ($q, $status) => $q->where('status', $status))
            ->when($this->assigneeFilter === 'me', fn ($q) => $q->where('assignee_id', auth()->id()))
            ->when(ctype_digit($this->assigneeFilter), fn ($q) => $q->where('assignee_id', (int) $this->assigneeFilter))
            ->when(ctype_digit($this->tagFilter), fn ($q) => $q->whereHas('tags', fn ($tags) => $tags->whereKey((int) $this->tagFilter)))
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function tagOptions()
    {
        return $this->project->tags()->orderBy('name')->get();
    }

    #[Computed]
    public function users()
    {
        return User::query()->orderBy('name')->get(['id', 'name']);
    }

    public function createTask(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assigneeId' => ['nullable', 'exists:users,id'],
            'dueDate' => ['nullable', 'date'],
        ]);

        $this->project->tasks()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?: null,
            'assignee_id' => $validated['assigneeId'] ?: null,
            'due_date' => $validated['dueDate'] ?: null,
            'creator_id' => auth()->id(),
        ]);

        $this->reset('title', 'description', 'assigneeId', 'dueDate');
        unset($this->tasks);
        Flux::modal('create-task')->close();
    }

    public function toggleDone(int $taskId): void
    {
        $task = $this->project->tasks()->findOrFail($taskId);

        $task->update([
            'status' => $task->status === TaskStatus::Done ? TaskStatus::Todo : TaskStatus::Done,
        ]);

        unset($this->tasks);
    }

    public function rendering($view): void
    {
        $view->title($this->project->name);
    }
};
?>

<div>
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>Projekte</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ $project->name }}</flux:heading>
            @if ($project->description)
                <flux:text class="mt-1">{{ $project->description }}</flux:text>
            @endif
        </div>

        <div class="flex items-center gap-3">
            <flux:button.group>
                <flux:button icon="list-bullet" disabled>Liste</flux:button>
                <flux:button icon="view-columns" href="{{ route('projects.board', $project) }}" wire:navigate>Board</flux:button>
            </flux:button.group>

            <flux:modal.trigger name="create-task">
                <flux:button variant="primary" icon="plus">Neue Aufgabe</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    <div class="mb-4 flex gap-3">
        <flux:select variant="listbox" wire:model.live="statusFilter" class="max-w-40">
            <flux:select.option value="open">Offen</flux:select.option>
            <flux:select.option value="all">Alle</flux:select.option>
            @foreach (TaskStatus::cases() as $status)
                <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select variant="listbox" wire:model.live="assigneeFilter" class="max-w-48">
            <flux:select.option value="">Alle Personen</flux:select.option>
            <flux:select.option value="me">Nur meine</flux:select.option>
            @foreach ($this->users as $user)
                <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->tagOptions->isNotEmpty())
            <flux:select variant="listbox" wire:model.live="tagFilter" class="max-w-48">
                <flux:select.option value="">Alle Tags</flux:select.option>
                @foreach ($this->tagOptions as $tag)
                    <flux:select.option value="{{ $tag->id }}">{{ $tag->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if ($this->tasks->isEmpty())
        <flux:callout icon="check-circle" heading="Keine Aufgaben" text="Mit diesen Filtern gibt es hier nichts zu tun." />
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="w-10"></flux:table.column>
                <flux:table.column>Aufgabe</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Zuständig</flux:table.column>
                <flux:table.column>Fällig</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->tasks as $task)
                    <flux:table.row wire:key="task-{{ $task->id }}">
                        <flux:table.cell>
                            <flux:checkbox :checked="$task->status === TaskStatus::Done" wire:click="toggleDone({{ $task->id }})" />
                        </flux:table.cell>
                        <flux:table.cell>
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="font-medium hover:underline">{{ $task->title }}</a>
                            @foreach ($task->tags as $tag)
                                <flux:badge size="sm" :color="$tag->color" class="ms-1">{{ $tag->name }}</flux:badge>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$task->status->color()">{{ $task->status->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{ $task->assignee?->name ?? '–' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($task->due_date)
                                <flux:text :class="$task->isOverdue() ? 'text-red-500' : ''">{{ $task->due_date->format('d.m.Y') }}</flux:text>
                            @else
                                –
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="create-task" class="md:w-[28rem]">
        <form wire:submit="createTask" class="space-y-6">
            <flux:heading size="lg">Neue Aufgabe</flux:heading>
            <flux:input wire:model="title" label="Titel" autofocus />
            <flux:textarea wire:model="description" label="Beschreibung" rows="3" />
            <flux:select variant="listbox" wire:model="assigneeId" label="Zuständig">
                <flux:select.option value="">Niemand</flux:select.option>
                @foreach ($this->users as $user)
                    <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:date-picker wire:model="dueDate" label="Fällig am" locale="de-DE" clearable />
            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary">Anlegen</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
