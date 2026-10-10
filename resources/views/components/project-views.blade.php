@props(['project', 'active', 'presence' => null])

@php
    $views = [
        'list' => [__('List'), 'list-bullet', 'projects.show'],
        'board' => [__('Board'), 'view-columns', 'projects.board'],
        'calendar' => [__('Calendar'), 'calendar-days', 'projects.calendar'],
        'timeline' => [__('Timeline'), 'arrow-turn-down-right', 'projects.timeline'],
        'statistics' => [__('Statistics'), 'presentation-chart-line', 'projects.statistics'],
    ];

    // In a group, Flux gives a filled first or last button one border fewer than an outlined one: without a transparent
    // stand-in the group gets a pixel narrower and, aligned to the right, the other views shift when the first or last one is shown.
    $sameWidth = fn ($loop) => $loop->first ? 'border-s! border-s-transparent!' : ($loop->last ? 'border-e! border-e-transparent!' : '');
@endphp

<x-presence :channel="$presence" :one="__(':name is here too')" :many="__(':count people are here too')" class="max-sm:hidden" />

{{-- Two separate groups, because the button group rounds only its first and last child. The current view is filled, not greyed out. --}}
<flux:button.group class="sm:hidden">
    @foreach ($views as $key => [$label, $icon, $route])
        <flux:button :icon="$icon" square :variant="$key === $active ? 'filled' : null" :class="$key === $active ? $sameWidth($loop) : ''" :aria-current="$key === $active ? 'page' : null" href="{{ route($route, $project) }}" wire:navigate :aria-label="$label" :tooltip="$label" />
    @endforeach
</flux:button.group>

<flux:button.group class="max-sm:hidden">
    @foreach ($views as $key => [$label, $icon, $route])
        <flux:button :icon="$icon" :variant="$key === $active ? 'filled' : null" :class="$key === $active ? $sameWidth($loop) : ''" :aria-current="$key === $active ? 'page' : null" href="{{ route($route, $project) }}" wire:navigate>{{ $label }}</flux:button>
    @endforeach
</flux:button.group>
