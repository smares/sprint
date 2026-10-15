@props(['task', 'editable' => false])

@php
    $people = collect([$task->assignee, ...$task->collaborators])->filter()->unique('id')->values();
    $names = $people->map->labelledName()->prepend($task->assignee_id === null && $people->isNotEmpty() ? __('Unassigned') : null)->filter()->join(', ');
@endphp

{{-- The people on a task (the assignee in front, or an empty seat while there is none but there are collaborators, see
     x-avatar-stack). Where the task can be edited this is also the quickest way to hand it on: one click on the faces
     opens the team (x-assignee-menu, once on the page), one more picks the assignee (or yourself, or nobody). --}}
@if (! $editable)
    <x-avatar-stack :users="$people" :unassigned="$task->assignee_id === null" />
    @if ($names !== '')
        <span class="sr-only">{{ $names }}</span>
    @endif
@else
    <button
        type="button"
        class="touch-target -m-1 flex cursor-pointer items-center rounded-full p-1 hover:bg-zinc-100 dark:hover:bg-white/10"
        aria-haspopup="menu"
        aria-expanded="false"
        aria-label="{{ __('Change assignee') }}{{ $names !== '' ? ': '.$names : '' }}"
        x-on:click.stop="$dispatch('open-assignee-menu', { task: {{ $task->id }}, assignee: {{ $task->assignee_id ?? 'null' }}, anchor: $el })"
    >
        @if ($people->isNotEmpty())
            <x-avatar-stack :users="$people" :unassigned="$task->assignee_id === null" />
        @else
            <x-unassigned-avatar icon="user-plus" />
        @endif
    </button>
@endif
