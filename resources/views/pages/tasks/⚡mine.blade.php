<?php

use App\Models\Task;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Meine Aufgaben')] class extends Component
{
    #[Computed]
    public function tasks()
    {
        return Task::query()
            ->with(['project', 'parent', 'status'])
            ->where(fn ($query) => $query
                ->where('assignee_id', auth()->id())
                ->orWhereHas('collaborators', fn ($collaborators) => $collaborators->whereKey(auth()->id()))
            )
            ->whereHas('status', fn ($status) => $status->where('is_done', false))
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }
};
?>

<div>
    <flux:heading size="xl" class="mb-6">Meine Aufgaben</flux:heading>

    @if ($this->tasks->isEmpty())
        <flux:callout icon="check-circle" heading="Alles erledigt" text="Dir sind keine offenen Aufgaben zugewiesen oder du bist an keiner beteiligt." />
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Aufgabe</flux:table.column>
                <flux:table.column>Projekt</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Fällig</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->tasks as $task)
                    <flux:table.row wire:key="task-{{ $task->id }}">
                        <flux:table.cell>
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="font-medium hover:underline">{{ $task->title }}</a>
                            @if ($task->parent)
                                <flux:text size="sm" class="block">in {{ $task->parent->title }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $task->project->name }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$task->status->color">{{ $task->status->name }}</flux:badge>
                        </flux:table.cell>
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
</div>
