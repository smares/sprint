@props(['views', 'defaults', 'canManage' => false, 'description', 'saveLabel', 'placeholder'])

{{-- views: Collection<SavedFilter>, defaults: the page's savedViewDefaults (mine, project, opens) --}}

{{-- The saved views of the list or of the board in a modal: open one, delete it, choose which one opens by default (for oneself,
     and for everybody in the project if one manages it) and save the current settings as a new one. The actions are those of
     the ManagesSavedViews concern of the page. --}}
@php
    $projectDefault = $views->firstWhere('id', $defaults['project']);
    $shared = $views->filter->isShared();
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

                    @can('delete', $saved)
                        <flux:button size="xs" variant="ghost" icon="trash" class="max-sm:size-10!" wire:click="deleteFilter({{ $saved->id }})" wire:confirm="{{ __('Delete view “:name”?', ['name' => $saved->name]) }}" aria-label="{{ __('Delete view “:name”', ['name' => $saved->name]) }}" tooltip="{{ __('Delete view') }}" />
                    @endcan
                </div>

                @if ($saved->isShared() || $saved->id === $defaults['opens'])
                    <div class="flex flex-wrap gap-1 ps-3">
                        @if ($saved->isShared())
                            <flux:badge size="sm">{{ __('Shared') }}</flux:badge>
                        @endif
                        @if ($saved->id === $defaults['project'])
                            <flux:badge size="sm" color="orange">{{ __('Default for everyone') }}</flux:badge>
                        @endif
                        @if ($saved->id === $defaults['opens'])
                            <flux:badge size="sm" color="amber" icon="check">{{ __('Opens for you') }}</flux:badge>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <flux:text>{{ __('No views saved yet.') }}</flux:text>
        @endforelse

        @if ($views->isNotEmpty())
            <flux:separator />

            {{-- What opens when the page is opened without settings in its address --}}
            <flux:select variant="listbox" wire:model.live="defaultChoice" :label="__('View when opening')">
                <flux:select.option value="">{{ $projectDefault ? __('As set for the project: “:name”', ['name' => $projectDefault->name]) : __('As set for the project: no view') }}</flux:select.option>
                <flux:select.option value="none">{{ __('No view') }}</flux:select.option>
                @foreach ($views as $saved)
                    <flux:select.option wire:key="choice-{{ $saved->id }}" value="{{ $saved->id }}">{{ $saved->name }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($canManage)
                <flux:select variant="listbox" wire:model.live="projectChoice" :label="__('View when opening, for everyone in the project')">
                    <flux:select.option value="none">{{ __('No view') }}</flux:select.option>
                    @foreach ($shared as $saved)
                        <flux:select.option wire:key="project-choice-{{ $saved->id }}" value="{{ $saved->id }}">{{ $saved->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
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
