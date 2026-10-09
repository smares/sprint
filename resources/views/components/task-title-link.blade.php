@props(['task', 'open' => false])

{{-- A task's title that opens it in the task flyout (a normal link with modifier keys), with blocked and repeat markers. --}}
<a href="{{ route('tasks.show', $task) }}" x-on:click="if ($event.metaKey || $event.ctrlKey || $event.shiftKey || $event.button !== 0) return; $event.preventDefault(); $wire.openTask({{ $task->id }})" @class(['font-medium hover:underline', 'text-blue-600 dark:text-blue-400' => $open])>{{ $task->title }}</a>
@if ($task->isBlocked())
    <flux:icon.lock-closed variant="micro" class="ms-1 inline text-amber-500" title="{{ __('Blocked') }}" />
@endif
@if ($task->isRecurring())
    <flux:icon.arrow-path variant="micro" class="ms-1 inline text-zinc-400" title="{{ __('Repeats :schedule', ['schedule' => $task->recurrenceLabel()]) }}" />
@endif
