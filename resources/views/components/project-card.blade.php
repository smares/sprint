@props(['project', 'favorite' => false, 'sortable' => false])

{{-- A project on the projects page; the star sits next to the link, not inside it, so it does not open the project. --}}
<div {{ $attributes->class('relative h-full') }}>
    <a href="{{ route('projects.show', $project) }}" wire:navigate class="block h-full">
        <flux:card class="h-full hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
            <flux:heading size="lg" @class(["pe-8" => ! $sortable, "pe-18" => $sortable])>{{ $project->name }}</flux:heading>
            <flux:text class="mt-1 line-clamp-2">{{ $project->description }}</flux:text>
            <div class="mt-4 flex gap-2">
                <flux:badge color="blue">{{ __(':count open', ['count' => $project->open_tasks_count]) }}</flux:badge>
                <flux:badge>{{ __(':count total', ['count' => $project->tasks_count]) }}</flux:badge>
            </div>
        </flux:card>
    </a>

    @if ($sortable)
        {{-- The whole card is a link, and a press on a link never starts dragging; so favorites move by this grip --}}
        <div wire:sort:handle class="absolute end-12 top-3 flex size-8 cursor-grab items-center justify-center rounded-md text-zinc-400 hover:bg-zinc-800/5 hover:text-zinc-600 active:cursor-grabbing dark:hover:bg-white/15 dark:hover:text-zinc-200" title="{{ __('Drag to sort') }}">
            <flux:icon.bars-2 variant="micro" />
        </div>
    @endif

    <flux:button
        size="sm"
        variant="ghost"
        icon="star"
        :icon:variant="$favorite ? 'solid' : 'outline'"
        :class="'absolute! end-3 top-3 '.($favorite ? 'text-amber-500! hover:text-amber-600!' : 'text-zinc-400! hover:text-zinc-600! dark:hover:text-zinc-200!')"
        wire:click="toggleFavorite({{ $project->id }})"
        :aria-pressed="$favorite ? 'true' : 'false'"
        :aria-label="$favorite ? __('Remove from favorites') : __('Add to favorites')"
        :tooltip="$favorite ? __('Remove from favorites') : __('Add to favorites')"
    />
</div>
