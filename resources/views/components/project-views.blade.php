@props(['project', 'active', 'presence' => null])

@php
    $views = [
        'list' => [__('List'), 'list-bullet', 'projects.show'],
        'board' => [__('Board'), 'view-columns', 'projects.board'],
        'calendar' => [__('Calendar'), 'calendar-days', 'projects.calendar'],
        'timeline' => [__('Timeline'), 'arrow-turn-down-right', 'projects.timeline'],
    ];
@endphp

<x-presence :channel="$presence" :one="__(':name is here too')" :many="__(':count people are here too')" class="max-sm:hidden" />

{{-- Two separate groups, because the button group rounds only its first and last child. The current view is filled, not greyed out. --}}
<flux:button.group class="sm:hidden">
    @foreach ($views as $key => [$label, $icon, $route])
        <flux:button :icon="$icon" square :variant="$key === $active ? 'filled' : null" :aria-current="$key === $active ? 'page' : null" href="{{ route($route, $project) }}" wire:navigate :aria-label="$label" :tooltip="$label" />
    @endforeach
</flux:button.group>

<flux:button.group class="max-sm:hidden">
    @foreach ($views as $key => [$label, $icon, $route])
        <flux:button :icon="$icon" :variant="$key === $active ? 'filled' : null" :aria-current="$key === $active ? 'page' : null" href="{{ route($route, $project) }}" wire:navigate>{{ $label }}</flux:button>
    @endforeach
</flux:button.group>
