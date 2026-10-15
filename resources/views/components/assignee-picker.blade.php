@props(['task', 'users', 'editable' => false])

@php
    $people = collect([$task->assignee, ...$task->collaborators])->filter()->unique('id')->values();
@endphp

{{-- The people on a task (the assignee in front, or an empty seat while there is none but there are collaborators, see x-avatar-stack). Where the task can be edited this is also the
     quickest way to hand it on: one click on the faces opens the team, one more picks the assignee (or yourself, or
     nobody). The page provides assign(taskId, userId). The list of people is only built once the menu is opened. --}}
@if (! $editable)
    <x-avatar-stack :users="$people" :unassigned="$task->assignee_id === null" />
@else
    <flux:dropdown position="bottom" align="start" x-data="{ built: false }">
        <button type="button" class="-m-1 flex cursor-pointer items-center rounded-full p-1 hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="built = true" aria-label="{{ __('Change assignee') }}" title="{{ __('Change assignee') }}">
            @if ($people->isNotEmpty())
                <x-avatar-stack :users="$people" :unassigned="$task->assignee_id === null" />
            @else
                <x-unassigned-avatar icon="user-plus" />
            @endif
        </button>

        <flux:menu class="max-h-80 overflow-y-auto">
            <template x-if="built">
                <div>
                    @if ($task->assignee_id !== auth()->id())
                        <flux:menu.item icon="hand-raised" wire:click="assign({{ $task->id }}, {{ auth()->id() }})">{{ __('Assign to me') }}</flux:menu.item>
                    @endif
                    @if ($task->assignee_id !== null)
                        <flux:menu.item icon="user-minus" wire:click="assign({{ $task->id }}, null)">{{ __('Unassigned') }}</flux:menu.item>
                    @endif
                    <flux:menu.separator />
                    @foreach ($users as $user)
                        <flux:menu.item wire:click="assign({{ $task->id }}, {{ $user->id }})" wire:key="assign-{{ $task->id }}-{{ $user->id }}">
                            <span class="flex items-center gap-2">
                                <x-user-avatar size="xs" :user="$user" />
                                <span class="flex-1 truncate">{{ $user->labelledName() }}</span>
                                @if ($user->id === $task->assignee_id)
                                    <flux:icon.check variant="micro" />
                                @endif
                            </span>
                        </flux:menu.item>
                    @endforeach
                </div>
            </template>
        </flux:menu>
    </flux:dropdown>
@endif
