@props(['project', 'active', 'presence' => null])

{{-- Breadcrumbs, title and the same toolbar for list, board, calendar and timeline. --}}
<flux:breadcrumbs class="mb-4">
    <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>{{ __('Projects') }}</flux:breadcrumbs.item>
    <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
</flux:breadcrumbs>

@if ($project->archived_at)
    <flux:callout class="mb-4" icon="archive-box" :heading="__('Archived')" :text="__('This project is archived and read-only.')" />
@endif

<div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
    <div>
        <flux:heading size="xl">{{ $project->name }}</flux:heading>
        @if ($project->description)
            <flux:text class="mt-1">{{ $project->description }}</flux:text>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <x-project-views :project="$project" :active="$active" :presence="$presence" />

        @can('edit', $project)
            {{-- On phones only the icon, like the views, so views, new task and the settings fit in one row --}}
            <flux:button variant="primary" icon="plus" square class="sm:hidden" x-on:click="$flux.modal('create-task').show()" :aria-label="__('New task')" :tooltip="__('New task')" />
            <flux:button variant="primary" icon="plus" class="max-sm:hidden" x-on:click="$flux.modal('create-task').show()">{{ __('New task') }}</flux:button>
        @endcan

        @can('manage', $project)
            <x-project-menu :project="$project" />
        @endcan
    </div>
</div>

@can('edit', $project)
    <livewire:task-create :project="$project" />
@endcan

@can('manage', $project)
    {{-- The dialogs of the setup menu load right after the page in one shared request, not as part of it. They stand
         outside the toolbar: as its flex items their empty roots would each add a gap and push the buttons off the right edge. --}}
    <livewire:project-settings :project="$project" defer.bundle />
    <livewire:project-statuses :project="$project" defer.bundle />
    <livewire:project-tags :project="$project" defer.bundle />
@endcan
