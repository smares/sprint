@props(['project'])

{{-- Setup entries for managers; Status and Tags open as modals, Felder and Mitglieder are pages. --}}
<flux:dropdown align="end">
    <flux:button icon="cog-6-tooth" aria-label="{{ __('Set up project') }}" />

    <flux:menu>
        <flux:menu.item icon="pencil-square" x-on:click="$flux.modal('project-settings').show()">{{ __('Settings') }}</flux:menu.item>
        <flux:menu.separator />
        <flux:menu.item icon="queue-list" x-on:click="$flux.modal('project-statuses').show()">{{ __('Status') }}</flux:menu.item>
        <flux:menu.item icon="tag" x-on:click="$flux.modal('project-tags').show()">{{ __('Tags') }}</flux:menu.item>
        <flux:menu.separator />
        <flux:menu.item icon="adjustments-horizontal" href="{{ route('projects.fields', $project) }}" wire:navigate>{{ __('Fields') }}</flux:menu.item>
        <flux:menu.item icon="users" href="{{ route('projects.members', $project) }}" wire:navigate>{{ __('Members') }}</flux:menu.item>
    </flux:menu>
</flux:dropdown>

{{-- The dialogs load right after the page in one shared request, not as part of it. --}}
<livewire:project-settings :project="$project" defer.bundle />
<livewire:project-statuses :project="$project" defer.bundle />
<livewire:project-tags :project="$project" defer.bundle />
