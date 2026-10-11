@props(['task'])

@php
    // Red once it has passed, amber for today and tomorrow, otherwise quiet; a done task is never urgent.
    $soon = ! $task->isDone() && $task->due_date?->lt(today()->addDays(2));
    $color = $task->isOverdue() ? 'red' : ($soon ? 'amber' : 'zinc');
@endphp

{{-- The due date in the lists and on the board, as a chip with a calendar. --}}
@if ($task->due_date)
    <flux:badge size="sm" :color="$color" icon="calendar" {{ $attributes->class('tabular-nums whitespace-nowrap')->merge(['data-due' => $color]) }}>{{ $task->due_date->isoFormat('L') }}</flux:badge>
@endif
