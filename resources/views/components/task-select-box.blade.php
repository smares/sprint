@props(['task'])

{{-- The box before a task while tasks are being selected (see EditsTasksInBulk and ListsMyTasks): it adds the task to the selection
     of the page, which the browser keeps until an action is chosen. A task can have several boxes (the calendar shows a task on every
     day it spans), so each one follows the selection instead of toggling it, also when it is ticked with the space bar (which sends
     no click). The box is there before the selection starts (see x-task-check-cell) and shown while the page selects. Ending the
     selection is the bar's job: unticking the last box does not end it.
     In a box as high as a line of text and as the done button (24px). --}}
<span class="flex size-6 shrink-0 items-center justify-center" data-select-box>
    <flux:checkbox
        class="touch-target"
        wire:key="select-{{ $task->id }}-on"
        x-effect="const on = $wire.selected.map(String).includes('{{ $task->id }}'); if ($el.checked !== on) { $el.checked = on }"
        x-on:change="
            const id = '{{ $task->id }}'
            const selected = $wire.selected.map(String)
            if ($el.checked && ! selected.includes(id)) { $wire.selected = [...selected, id] }
            if (! $el.checked && selected.includes(id)) { $wire.selected = selected.filter((other) => other !== id) }
        "
        aria-label="{{ __('Select task') }}: {{ $task->title }}"
    />
</span>
