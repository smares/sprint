@props(['project', 'favorite' => false])

{{-- A project on the projects page. The title is the real link (new tab, keyboard); a click anywhere else on the card
     opens the project too. The card itself is no link, so it can be dragged when it is a favorite. --}}
<div {{ $attributes->class('relative h-full') }}>
    <flux:card
        class="h-full cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-700/50"
        x-on:click="if ($event.target.closest('a, button')) return; ($event.metaKey || $event.ctrlKey) ? window.open($el.querySelector('a').href, '_blank') : $el.querySelector('a').click()"
    >
        <flux:heading size="lg" class="pe-8">
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="hover:underline">{{ $project->name }}</a>
        </flux:heading>
        <flux:text class="mt-1 line-clamp-2">{{ $project->description }}</flux:text>
        <div class="mt-4 flex gap-2">
            <flux:badge color="blue">{{ __(':count open', ['count' => $project->open_tasks_count]) }}</flux:badge>
            <flux:badge>{{ __(':count total', ['count' => $project->tasks_count]) }}</flux:badge>
        </div>
    </flux:card>

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
