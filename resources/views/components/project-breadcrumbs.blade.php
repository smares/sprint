@props(['project', 'current' => null])

{{-- Projects › project (with its tile) › …: the trail above every project page and task. Further levels go in the slot,
     the page itself in `current`; without either the project is the page and no link. --}}
@php($deeper = $current !== null || $slot->isNotEmpty())
<flux:breadcrumbs {{ $attributes->class('mb-4') }}>
    <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>{{ __('Projects') }}</flux:breadcrumbs.item>
    <flux:breadcrumbs.item :href="$deeper ? route('projects.show', $project) : null" :wire:navigate="$deeper">
        <span class="inline-flex items-center gap-1.5"><x-project-icon :project="$project" size="sm" />{{ $project->name }}</span>
    </flux:breadcrumbs.item>
    {{ $slot }}
    @if ($current !== null)
        <flux:breadcrumbs.item>{{ $current }}</flux:breadcrumbs.item>
    @endif
</flux:breadcrumbs>
