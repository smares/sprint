@props(['task'])

{{-- Side panel with the full task next to a list or board; a full-screen sheet on phones. --}}
@if ($task)
    <aside
        aria-label="{{ __('Task') }}"
        class="fixed inset-0 z-40 overflow-y-auto bg-white p-5 dark:bg-zinc-800 lg:inset-y-auto lg:start-auto lg:end-0 lg:top-14 lg:bottom-0 lg:z-20 lg:w-[38rem] lg:border-s lg:border-zinc-200 lg:shadow-xl lg:dark:border-zinc-700"
    >
        <livewire:pages::tasks.show :task="$task" :panel="true" :key="'panel-'.$task->id" />
    </aside>
@endif
