@props(['task'])

{{-- The full task in a Flux flyout over the list or board (a sheet on phones): behind it nothing can be used, so one task is worked on at a time. --}}
@if ($task)
    <flux:modal
        name="task-panel"
        variant="flyout"
        :closable="false"
        wire:close="closeTask"
        x-data
        x-init="$nextTick(() => $flux.modal('task-panel').show())"
        aria-label="{{ __('Task') }}"
        class="w-full p-5! md:max-w-[38rem]"
    >
        <livewire:pages::tasks.show :task="$task" :panel="true" :key="'panel-'.$task->id" />
    </flux:modal>
@endif
