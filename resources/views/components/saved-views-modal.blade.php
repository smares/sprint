@props(['views', 'defaults', 'canManage' => false, 'description', 'saveLabel', 'placeholder'])

{{-- views: Collection<SavedFilter>, defaults: the page's savedViewDefaults (mine, project, opens) --}}

{{-- The saved views of the list or of the board in a modal: open one, delete it, choose which one opens by default (for oneself,
     and for everybody in the project if one manages it) and save the current settings as a new one. The actions are those of
     the ManagesSavedViews concern of the page. --}}
@php
    $projectDefault = $views->firstWhere('id', $defaults['project']);
    $shared = $views->filter->isShared();
@endphp

{{-- A missing name shown after a failed save is gone when the dialog is closed --}}
<flux:modal name="saved-views" class="w-full max-w-md" x-on:close="$el.querySelector('[data-invalid]') && $wire.clearSavedViewMessages()">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">{{ __('Saved views') }}</flux:heading>
            <flux:text class="mt-1">{{ $description }}</flux:text>
        </div>

        @forelse ($views as $saved)
            <div wire:key="saved-{{ $saved->id }}" class="space-y-1">
                <div class="flex items-center gap-2">
                    <flux:button variant="subtle" class="min-w-0 flex-1 justify-start" wire:click="applyView({{ $saved->id }})">
                        <span class="truncate">{{ $saved->name }}</span>
                    </flux:button>

                    @can('delete', $saved)
                        <flux:button size="xs" variant="ghost" icon="trash" class="max-sm:size-10!" wire:click="deleteView({{ $saved->id }})" wire:confirm="{{ __('Delete view “:name”?', ['name' => $saved->name]) }}" aria-label="{{ __('Delete view “:name”', ['name' => $saved->name]) }}" tooltip="{{ __('Delete view') }}" />
                    @endcan
                </div>

                @if ($saved->isShared() || $saved->id === $defaults['opens'])
                    <div class="flex flex-wrap gap-1 ps-3">
                        @if ($saved->isShared())
                            <flux:badge size="sm">{{ __('Shared') }}</flux:badge>
                        @endif
                        @if ($saved->id === $defaults['project'])
                            <flux:badge size="sm" color="orange">{{ __('Set for everyone') }}</flux:badge>
                        @endif
                        @if ($saved->id === $defaults['opens'])
                            <flux:badge size="sm" color="amber" icon="check">{{ __('Applied for you') }}</flux:badge>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <flux:text>{{ __('No views saved yet.') }}</flux:text>
        @endforelse

        @if ($views->isNotEmpty())
            <flux:separator />

            {{-- What is applied when the page is opened without settings in its address; saved with its own button --}}
            <form wire:submit="saveDefaults" class="space-y-3">
                <div>
                    <flux:heading>{{ __('Apply when opening') }}</flux:heading>
                    <flux:text size="sm" class="mt-1">{{ __('What is applied when the page is opened without settings in its address.') }}</flux:text>
                </div>

                <flux:select variant="listbox" wire:model="defaultChoice" :label="__('For you')">
                    <flux:select.option value="">{{ $projectDefault ? __('As the project sets it: “:name”', ['name' => $projectDefault->name]) : __('As the project sets it: nothing') }}</flux:select.option>
                    <flux:select.option value="none">{{ __('Apply nothing') }}</flux:select.option>
                    @foreach ($views as $saved)
                        <flux:select.option wire:key="choice-{{ $saved->id }}" value="{{ $saved->id }}">{{ $saved->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($canManage)
                    <flux:select variant="listbox" wire:model="projectChoice" :label="__('For everyone in the project')">
                        <flux:select.option value="none">{{ __('Apply nothing') }}</flux:select.option>
                        @foreach ($shared as $saved)
                            <flux:select.option wire:key="project-choice-{{ $saved->id }}" value="{{ $saved->id }}">{{ $saved->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        @endif

        <flux:separator />

        <form wire:submit="saveCurrentView" class="space-y-3">
            <flux:heading>{{ __('New view') }}</flux:heading>
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
