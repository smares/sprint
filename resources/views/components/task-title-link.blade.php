@props(['task'])

{{-- A task's title that opens it in the task flyout (a normal link with modifier keys), with blocked and repeat markers. --}}
<a href="{{ route('tasks.show', $task) }}" x-on:click="$openTask($event, {{ $task->id }})" data-task-title x-bind:class="{ 'text-accent-content': $store.taskFlyout.id === '{{ $task->id }}' }" class="font-medium hover:underline">{{ $task->title }}</a>
@if ($task->isBlocked())
    <flux:icon.lock-closed variant="micro" class="ms-1 inline text-amber-500" title="{{ __('Blocked') }}" />
@endif
@if ($task->isRecurring())
    <flux:icon.arrow-path variant="micro" class="ms-1 inline text-zinc-400" title="{{ __('Repeats :schedule', ['schedule' => $task->recurrenceLabel()]) }}" />
@endif
