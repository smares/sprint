@props(['project'])

{{-- Setup entries for managers; Status and Tags open as modals, Felder and Mitglieder are pages. --}}
<flux:dropdown align="end">
    <flux:button icon="cog-6-tooth" aria-label="Projekt einrichten" />

    <flux:menu>
        <flux:menu.item icon="queue-list" x-on:click="$flux.modal('project-statuses').show()">Status</flux:menu.item>
        <flux:menu.item icon="tag" x-on:click="$flux.modal('project-tags').show()">Tags</flux:menu.item>
        <flux:menu.separator />
        <flux:menu.item icon="adjustments-horizontal" href="{{ route('projects.fields', $project) }}" wire:navigate>Felder</flux:menu.item>
        <flux:menu.item icon="users" href="{{ route('projects.members', $project) }}" wire:navigate>Mitglieder</flux:menu.item>
    </flux:menu>
</flux:dropdown>

<livewire:project-statuses :project="$project" />
<livewire:project-tags :project="$project" />
