@props(['task'])

{{-- The full task in a Flux flyout over the list or board (a sheet on phones): behind it nothing can be used, so one task is worked on at a time.
     Closing (Esc, a click next to it, the close button) asks first when the task has unsaved input, see taskFlyout in app.js. --}}
@if ($task)
    <flux:modal
        name="task-panel"
        variant="flyout"
        :closable="false"
        :dismissible="false"
        :escapable="false"
        wire:close="closeTask"
        x-data="taskFlyout({{ \Illuminate\Support\Js::from(__('Discard your unsaved changes to this task?')) }}, {{ \Illuminate\Support\Js::from($task->title) }})"
        x-on:keydown.window.capture="onKeydown($event)"
        x-on:click="onClick($event)"
        x-on:close-task="guard($event)"
        x-on:open-task="guard($event)"
        class="w-full p-5! md:max-w-[38rem]"
    >
        <livewire:pages::tasks.show :task="$task" :panel="true" :key="'panel-'.$task->id" />
    </flux:modal>
@endif
