@props(['task'])

@php
    // "Today" is the day of the person looking (their time zone), not the server's. Red once the due date has passed,
    // amber for today and tomorrow, otherwise quiet; a done task is never urgent.
    $viewer = auth()->user();
    $today = \App\Services\DateService::today($viewer);
    $due = $task->due_date;
    $soon = $due !== null && ! $task->isDone() && $due->toDateString() < $today->copy()->addDays(2)->toDateString();
    $color = $task->isOverdue($today) ? 'red' : ($soon ? 'amber' : 'zinc');
    $relative = $due ? \App\Services\DateService::relativeLabel($due, $viewer) : null;
@endphp

{{-- The due date in the lists and on the board, as a chip with a calendar: "Tomorrow" within a week, otherwise the date.
     The full date with the weekday is the title (a plain attribute: lists have many rows) and is read out after the word. --}}
@if ($due)
    <flux:badge size="sm" :color="$color" icon="calendar" title="{{ \App\Services\DateService::fullLabel($due) }}" {{ $attributes->class('tabular-nums whitespace-nowrap')->merge(['data-due' => $color]) }}>{{ $relative ?? $due->isoFormat('L') }}@if ($relative !== null)<span class="sr-only"> ({{ \App\Services\DateService::fullLabel($due) }})</span>@endif</flux:badge>
@endif
