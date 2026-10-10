@props(['task'])

{{-- The due date in the lists, red once it has passed. --}}
@if ($task->due_date)
    <flux:text :class="$task->isOverdue() ? 'text-red-500' : ''">{{ $task->due_date->isoFormat('L') }}</flux:text>
@endif
