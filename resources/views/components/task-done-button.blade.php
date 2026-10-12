@props(['task', 'editable' => true])

@php
    // Completing is celebrated before the server is asked: the check fills green with a little pop, the row strikes its
    // title through and fades (data-completing, see app.css), and only then is the task saved, which usually removes the row.
    // The wait matches that fade: 0.1s delay and 0.4s transition there.
    $celebrationMs = 450;
    $onClick = $task->isDone()
        ? "\$wire.toggleDone({$task->id})"
        : "if (completing) return; completing = true; \$el.closest('[data-task-id]')?.setAttribute('data-completing', ''); "
            ."setTimeout(() => \$wire.toggleDone({$task->id}), window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : {$celebrationMs})";
@endphp

{{-- The round check before a task in the lists: marks it done or opens it again (the component offers toggleDone). --}}
<flux:button
    size="xs"
    variant="ghost"
    square
    :class="'shrink-0 max-sm:size-10! '.($task->isDone() ? 'text-green-600! dark:text-green-500!' : 'text-zinc-400! hover:text-green-600! dark:text-zinc-500! dark:hover:text-green-500!')"
    :disabled="! $editable"
    x-data="{ completing: false }"
    x-on:click="{{ $onClick }}"
    aria-label="{{ $task->isDone() ? __('Reopen task') : __('Mark as done') }}"
    tooltip="{{ $task->isDone() ? __('Reopen task') : __('Mark as done') }}"
>
    @if ($task->isDone())
        <flux:icon.check-circle variant="solid" class="size-4 max-sm:size-5" />
    @else
        <flux:icon.check-circle variant="outline" class="size-4 max-sm:size-5" x-show="! completing" />
        <flux:icon.check-circle variant="solid" class="task-check-pop size-4 text-green-600 max-sm:size-5 dark:text-green-500" x-show="completing" x-cloak />
    @endif
</flux:button>
