@props(['users'])

{{-- The menu to hand a task to someone: one per page, shown at the faces of the task that was clicked (see
     x-assignee-picker), so the people are in the page once and not once for every task. The page provides
     assign(taskId, userId). wire:ignore keeps it open and focused while the page renders again; the people in it are
     those of the page's load. --}}
<div
    data-assignee-menu
    wire:ignore
    x-data="assigneeMenu({{ (int) auth()->id() }})"
    x-on:open-assignee-menu.window="show($event.detail)"
    x-on:keydown.escape.window="close(true)"
>
    <div
        x-ref="menu"
        x-show="isOpen"
        x-cloak
        x-anchor.bottom-start.offset.4="anchor"
        x-on:click.outside="close()"
        x-on:keydown.arrow-down.prevent="move(1)"
        x-on:keydown.arrow-up.prevent="move(-1)"
        x-on:keydown.tab="close(true)"
        role="menu"
        aria-label="{{ __('Change assignee') }}"
        class="z-50 max-h-80 w-64 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-600 dark:bg-zinc-700"
    >
        @php
            $item = 'flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm text-zinc-800 hover:bg-zinc-100 focus:bg-zinc-100 focus:outline-hidden dark:text-white dark:hover:bg-zinc-600 dark:focus:bg-zinc-600';
        @endphp

        <button type="button" role="menuitem" class="{{ $item }}" x-show="assigneeId !== meId" x-on:click="pick(meId)">
            <flux:icon.hand-raised variant="micro" class="text-zinc-400" />
            {{ __('Assign to me') }}
        </button>
        <button type="button" role="menuitem" class="{{ $item }}" x-show="assigneeId !== null" x-on:click="pick(null)">
            <flux:icon.user-minus variant="micro" class="text-zinc-400" />
            {{ __('Nobody') }}
        </button>
        <div class="my-1 border-t border-zinc-200 dark:border-zinc-600"></div>

        @foreach ($users as $user)
            <button type="button" role="menuitemradio" class="{{ $item }}" x-bind:aria-checked="assigneeId === {{ $user->id }}" x-on:click="pick({{ $user->id }})">
                <x-user-avatar size="xs" :user="$user" />
                <span class="min-w-0 flex-1 truncate">{{ $user->labelledName() }}</span>
                <flux:icon.check variant="micro" x-show="assigneeId === {{ $user->id }}" x-cloak />
            </button>
        @endforeach
    </div>
</div>
