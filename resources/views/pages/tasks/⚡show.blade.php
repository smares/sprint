<?php

use App\Models\Task;
use App\Models\User;
use App\TaskStatus;
use Flux\Flux;
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

    public function mount(): void
    {
        $this->title = $this->task->title;
        $this->description = $this->task->description ?? '';
        $this->status = $this->task->status->value;
        $this->assigneeId = (string) ($this->task->assignee_id ?? '');
        $this->dueDate = $this->task->due_date?->format('Y-m-d') ?? '';
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
        ]);

        $this->task->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?: null,
            'status' => $validated['status'],
            'assignee_id' => $validated['assigneeId'] ?: null,
            'due_date' => $validated['dueDate'] ?: null,
        ]);

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

        <div class="flex gap-3">
            <flux:button type="submit" variant="primary">Speichern</flux:button>
            <flux:spacer />
            <flux:modal.trigger name="delete-task">
                <flux:button variant="danger" icon="trash">Löschen</flux:button>
            </flux:modal.trigger>
        </div>
    </form>

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
            <flux:text>Die Aufgabe und alle Kommentare werden unwiderruflich gelöscht.</flux:text>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Abbrechen</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">Löschen</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
