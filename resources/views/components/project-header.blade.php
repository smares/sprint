@props(['project', 'active', 'presence' => null])

{{-- Breadcrumbs, title and the same toolbar for list, board, calendar, timeline and statistics. --}}
<x-project-breadcrumbs :project="$project" />

@if ($project->archived_at)
    <flux:callout class="mb-4" icon="archive-box" :heading="__('Archived')" :text="__('This project is archived and read-only.')" />
@endif

<div class="mb-6 flex items-start justify-between gap-4">
    <div class="flex items-start gap-3">
        <x-project-icon :project="$project" size="lg" />
        <div class="min-w-0">
            <flux:heading level="1" size="xl">{{ $project->name }}</flux:heading>
            @if ($project->description)
                {{-- On phones two lines, a tap on the text shows all of it --}}
                <div x-data="{ open: false }">
                    <flux:text class="mt-1 max-sm:cursor-pointer" x-bind:class="open ? '' : 'max-sm:line-clamp-2'" x-on:click="open = ! open">{{ $project->description }}</flux:text>
                </div>
            @endif
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-2">
        {{-- The views (list, board, …) are switched in the sidebar under the project, below lg also in the row underneath --}}
        <x-presence :channel="$presence" :one="__(':name is here too')" :many="__(':count people are here too')" class="max-sm:hidden" />

        @can('edit', $project)
            {{-- On phones only the icon, so new task and the settings stay small next to the title --}}
            <flux:button variant="primary" icon="plus" square class="sm:hidden" x-on:click="$flux.modal('create-task').show()" :aria-label="__('New task')" :tooltip="__('New task')" />
            <flux:button variant="primary" icon="plus" class="max-sm:hidden" x-on:click="$flux.modal('create-task').show()">{{ __('New task') }}</flux:button>
        @endcan

        @can('manage', $project)
            <x-project-menu :project="$project" />
        @endcan
    </div>
</div>

<x-project-views :project="$project" :active="$active" class="-mt-2 mb-6" />

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
