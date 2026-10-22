@props(['views', 'defaults', 'canManage' => false, 'description', 'saveLabel', 'placeholder'])

{{-- views: Collection<SavedFilter>, defaults: the page's savedViewDefaults (mine, project, opens) --}}

{{-- The saved views of the list or of the board in a modal: open one, delete it, say which one opens by default (for oneself, and
     for everybody in the project if one manages it) and save the current settings as a new one. The actions are those of the
     ManagesSavedViews concern of the page. --}}
@php
    // What this person has chosen (0: none, null: follow the project), the view that opens for them, the project's default
    $mine = $defaults['mine'];
    $opens = $defaults['opens'];
    $projectDefault = $views->firstWhere('id', $defaults['project']);
@endphp

<flux:modal name="saved-filters" class="w-full max-w-md">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">{{ __('Saved views') }}</flux:heading>
            <flux:text class="mt-1">{{ $description }}</flux:text>
        </div>

        {{-- What the project opens by default can be switched off for oneself, and on again --}}
        @if ($mine === 0)
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:text size="sm">{{ __('No view opens by default for you.') }}</flux:text>
                <flux:button size="xs" variant="ghost" wire:click="followProjectDefault">{{ __('Use the default of the project') }}</flux:button>
            </div>
        @elseif ($mine === null && $projectDefault)
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:text size="sm">{{ __('Default of the project: “:name”', ['name' => $projectDefault->name]) }}</flux:text>
                <flux:button size="xs" variant="ghost" wire:click="useNoDefaultForMe">{{ __('Not for me') }}</flux:button>
            </div>
        @endif

        @forelse ($views as $saved)
            <div wire:key="saved-{{ $saved->id }}" class="space-y-1">
                <div class="flex items-center gap-2">
                    <flux:button variant="subtle" class="min-w-0 flex-1 justify-start" wire:click="applyFilter({{ $saved->id }})">
                        <span class="truncate">{{ $saved->name }}</span>
                    </flux:button>

                    <flux:dropdown align="end">
                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" class="max-sm:size-10!" aria-label="{{ __('More actions for “:name”', ['name' => $saved->name]) }}" tooltip="{{ __('More actions') }}" />

                        <flux:menu>
                            @if ($mine === $saved->id)
                                <flux:menu.item icon="check" wire:click="followProjectDefault">{{ __('Remove my default') }}</flux:menu.item>
                            @else
                                <flux:menu.item icon="check" wire:click="useAsMyDefault({{ $saved->id }})">{{ __('Open this by default for me') }}</flux:menu.item>
                            @endif

                            @if ($canManage && $saved->isShared())
                                @if ($saved->is_default)
                                    <flux:menu.item icon="user-group" wire:click="clearProjectDefault">{{ __('Remove default for everyone') }}</flux:menu.item>
                                @else
                                    <flux:menu.item icon="user-group" wire:click="makeProjectDefault({{ $saved->id }})">{{ __('Open this by default for everyone') }}</flux:menu.item>
                                @endif
                            @endif

                            @can('delete', $saved)
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" wire:click="deleteFilter({{ $saved->id }})" wire:confirm="{{ __('Delete view “:name”?', ['name' => $saved->name]) }}">{{ __('Delete view') }}</flux:menu.item>
                            @endcan
                        </flux:menu>
                    </flux:dropdown>
                </div>

                @if ($saved->isShared() || $saved->id === $opens)
                    <div class="flex flex-wrap gap-1 ps-3">
                        @if ($saved->isShared())
                            <flux:badge size="sm">{{ __('Shared') }}</flux:badge>
                        @endif
                        @if ($saved->id === $defaults['project'])
                            <flux:badge size="sm" color="orange">{{ __('Default for everyone') }}</flux:badge>
                        @endif
                        @if ($saved->id === $opens)
                            <flux:badge size="sm" color="amber" icon="check">{{ __('Opens for you') }}</flux:badge>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <flux:text>{{ __('No views saved yet.') }}</flux:text>
        @endforelse

        <flux:separator />

        <form wire:submit="saveCurrentFilter" class="space-y-3">
            <flux:input wire:model="saveName" :label="$saveLabel" :placeholder="$placeholder" />
            @if ($canManage)
                <flux:checkbox wire:model="saveShared" :label="__('Visible to everyone in the project')" />
            @endif
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="bookmark">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </div>
</flux:modal>
