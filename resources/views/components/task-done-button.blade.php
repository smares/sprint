@props(['task', 'editable' => true, 'action' => 'toggleDone', 'compact' => false])

@php
    // Compact: as high as a line of text (24px) on every screen, for the cards, where it stands beside the first line of the title;
    // otherwise phones get a 40px button for the finger. Touch screens enlarge the hit area of the small one anyway (app.css).
    $touchSize = $compact ? '' : 'max-sm:size-10! ';
    $iconSize = $compact ? 'size-4' : 'size-4 max-sm:size-5';

    // Completing is celebrated before the server is asked: the check fills green with a little pop, the row strikes its
    // title through and fades (data-completing, see app.css), and only then is the task saved, which usually removes the row.
    // The wait matches that fade: 0.1s delay and 0.4s transition there.
    $celebrationMs = 450;
    $onClick = $task->isDone()
        ? "\$wire.{$action}({$task->id})"
        : "if (completing) return; completing = true; \$el.closest('[data-task-id]')?.setAttribute('data-completing', ''); "
            ."setTimeout(() => \$wire.{$action}({$task->id}), window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : {$celebrationMs})";
@endphp

{{-- The round check before a task in the lists, boards, calendar, timeline and subtasks: marks it done or opens it again (the component offers the
     action, toggleDone by default). --}}
<flux:button
    size="xs"
    variant="ghost"
    square
    :class="'shrink-0 '.$touchSize.($task->isDone() ? 'text-green-600! dark:text-green-500!' : 'text-zinc-400! hover:text-green-600! dark:text-zinc-500! dark:hover:text-green-500!')"
    :disabled="! $editable"
    x-data="{ completing: false }"
    x-on:click="{{ $onClick }}"
    aria-label="{{ $task->isDone() ? __('Reopen task') : __('Mark as done') }}: {{ $task->title }}"
    tooltip="{{ $task->isDone() ? __('Reopen task') : __('Mark as done') }}"
>
    @if ($task->isDone())
        <flux:icon.check-circle variant="solid" :class="$iconSize" />
    @else
        <flux:icon.check-circle variant="outline" :class="$iconSize" x-show="! completing" />
        <flux:icon.check-circle variant="solid" :class="'task-check-pop text-green-600 dark:text-green-500 '.$iconSize" x-show="completing" x-cloak />
    @endif
</flux:button>
