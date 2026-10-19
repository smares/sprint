@props(['task', 'selected' => false])

{{-- The box before a task while tasks are being selected (see EditsTasksInBulk): ticking it adds the task to the selection of the
     page, which is kept in the browser until an action is chosen. `selected` is how the server drew it. --}}
{{-- In a box as high as a line of text and as the done button (24px), so the two stand on one line with the title --}}
<span class="flex size-6 shrink-0 items-center justify-center">
    <flux:checkbox
        wire:key="select-{{ $task->id }}-on"
        :checked="$selected"
        x-on:click="$wire.selected = $wire.selected.includes('{{ $task->id }}') ? $wire.selected.filter((id) => id !== '{{ $task->id }}') : [...$wire.selected, '{{ $task->id }}']"
        aria-label="{{ __('Select task') }}"
    />
</span>
