@props(['task', 'endsWhenEmpty' => false])

{{-- The box before a task while tasks are being selected (see EditsTasksInBulk and ListsMyTasks): it adds the task to the selection
     of the page, which the browser keeps until an action is chosen. A task can have several boxes (the calendar shows a task on every
     day it spans), so each one follows the selection instead of toggling it, also when it is ticked with the space bar (which sends
     no click). `endsWhenEmpty`: unticking the last one ends the selection (the list, where ticking a box started it).
     In a box as high as a line of text and as the done button (24px), so the two stand on one line with the title. --}}
<span class="flex size-6 shrink-0 items-center justify-center" data-select-box>
    <flux:checkbox
        class="touch-target"
        wire:key="select-{{ $task->id }}-on"
        x-effect="$el.checked = $wire.selected.map(String).includes('{{ $task->id }}')"
        x-on:change="
            const id = '{{ $task->id }}'
            const selected = $wire.selected.map(String)
            if ($el.checked && ! selected.includes(id)) { $wire.selected = [...selected, id] }
            if (! $el.checked && selected.includes(id)) { $wire.selected = selected.filter((other) => other !== id) }
            {{ $endsWhenEmpty ? 'if ($wire.selected.length === 0) { $wire.stopSelecting() }' : '' }}
        "
        aria-label="{{ __('Select task') }}: {{ $task->title }}"
    />
</span>
