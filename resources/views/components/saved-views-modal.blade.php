@props(['views', 'defaults', 'canManage' => false, 'description', 'saveLabel', 'placeholder'])

{{-- The saved views of the list or of the board in a modal: open one, delete it, say which one opens by default (for oneself, and
     for everybody in the project if one manages it) and save the current settings as a new one. The actions are those of the
     ManagesSavedViews concern of the page. --}}
@php
    // What opens by default for this person: their own pick (0: none), else the project's default
    $opens = $defaults['mine'] === 0 ? null : ($views->firstWhere('id', $defaults['mine'])?->id ?? $defaults['project']);
    $projectDefault = $views->firstWhere('id', $defaults['project']);
@endphp

<flux:modal name="saved-filters" class="w-full max-w-md">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">{{ __('Saved views') }}</flux:heading>
            <flux:text class="mt-1">{{ $description }}</flux:text>
        </div>

        @forelse ($views as $saved)
            <div wire:key="saved-{{ $saved->id }}" class="space-y-1">
                <div class="flex items-center gap-2">
                    <flux:button variant="subtle" class="min-w-0 flex-1 justify-start" wire:click="applyFilter({{ $saved->id }})">
                        <span class="truncate">{{ $saved->name }}</span>
                    </flux:button>

                    @if ($saved->id === $opens)
                        <flux:icon.star variant="solid" class="size-4 shrink-0 text-amber-500" aria-label="{{ __('Opens by default for you') }}" title="{{ __('Opens by default for you') }}" />
                    @endif

                    <flux:dropdown align="end">
                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" aria-label="{{ __('More actions') }}" tooltip="{{ __('More actions') }}" />

                        <flux:menu>
                            @if ($defaults['mine'] === $saved->id)
                                <flux:menu.item icon="star" wire:click="followProjectDefault">{{ __('Remove my default') }}</flux:menu.item>
                            @else
                                <flux:menu.item icon="star" wire:click="useAsMyDefault({{ $saved->id }})">{{ __('Open this by default for me') }}</flux:menu.item>
                            @endif

                            @if ($canManage && $saved->isShared())
                                @if ($saved->is_default)
                                    <flux:menu.item icon="user-group" wire:click="clearProjectDefault">{{ __('Remove default for everyone') }}</flux:menu.item>
                                @else
                                    <flux:menu.item icon="user-group" wire:click="makeProjectDefault({{ $saved->id }})">{{ __('Open this by default for everyone') }}</flux:menu.item>
                                @endif
                            @endif
                        </flux:menu>
                    </flux:dropdown>

                    @can('delete', $saved)
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteFilter({{ $saved->id }})" wire:confirm="{{ __('Delete view “:name”?', ['name' => $saved->name]) }}" aria-label="{{ __('Delete view') }}" tooltip="{{ __('Delete view') }}" />
                    @endcan
                </div>

                @if ($saved->isShared())
                    <div class="flex flex-wrap gap-1 ps-3">
                        <flux:badge size="sm">{{ __('Shared') }}</flux:badge>
                        @if ($saved->id === $defaults['project'])
                            <flux:badge size="sm" color="orange">{{ __('Default for everyone') }}</flux:badge>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <flux:text>{{ __('No views saved yet.') }}</flux:text>
        @endforelse

        {{-- What the project opens by default can be switched off for oneself, and on again --}}
        @if ($defaults['mine'] === 0)
            <div class="flex items-center justify-between gap-2">
                <flux:text size="sm">{{ __('No view opens by default for you.') }}</flux:text>
                @if ($projectDefault)
                    <flux:button size="xs" variant="ghost" wire:click="followProjectDefault">{{ __('Use the default of the project') }}</flux:button>
                @endif
            </div>
        @elseif ($defaults['mine'] === null && $projectDefault)
            <div class="flex items-center justify-between gap-2">
                <flux:text size="sm">{{ __('Opens by default: “:name”', ['name' => $projectDefault->name]) }}</flux:text>
                <flux:button size="xs" variant="ghost" wire:click="useNoDefaultForMe">{{ __('Not for me') }}</flux:button>
            </div>
        @endif

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
