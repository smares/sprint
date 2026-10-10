@props(['task', 'editable' => true])

{{-- The round check before a task in the lists: marks it done or opens it again (the component offers toggleDone). --}}
<flux:button
    size="xs"
    variant="ghost"
    icon="check-circle"
    icon:variant="{{ $task->isDone() ? 'solid' : 'outline' }}"
    :class="'shrink-0 max-sm:size-10! '.($task->isDone() ? 'text-green-600! dark:text-green-500!' : 'text-zinc-400! hover:text-green-600! dark:text-zinc-500! dark:hover:text-green-500!')"
    :disabled="! $editable"
    wire:click="toggleDone({{ $task->id }})"
    aria-label="{{ $task->isDone() ? __('Reopen task') : __('Mark as done') }}"
    tooltip="{{ $task->isDone() ? __('Reopen task') : __('Mark as done') }}"
/>
