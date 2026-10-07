@props(['project', 'active'])

@php
    $views = [
        'list' => [__('List'), 'list-bullet', 'projects.show'],
        'board' => [__('Board'), 'view-columns', 'projects.board'],
        'calendar' => [__('Calendar'), 'calendar-days', 'projects.calendar'],
        'timeline' => [__('Timeline'), 'arrow-turn-down-right', 'projects.timeline'],
    ];
@endphp

{{-- Two separate groups, because the button group rounds only its first and last child. --}}
<flux:button.group class="sm:hidden">
    @foreach ($views as $key => [$label, $icon, $route])
        @if ($key === $active)
            <flux:button :icon="$icon" square disabled aria-label="{{ $label }}" />
        @else
            <flux:button :icon="$icon" square href="{{ route($route, $project) }}" wire:navigate aria-label="{{ $label }}" />
        @endif
    @endforeach
</flux:button.group>

<flux:button.group class="max-sm:hidden">
    @foreach ($views as $key => [$label, $icon, $route])
        @if ($key === $active)
            <flux:button :icon="$icon" disabled>{{ $label }}</flux:button>
        @else
            <flux:button :icon="$icon" href="{{ route($route, $project) }}" wire:navigate>{{ $label }}</flux:button>
        @endif
    @endforeach
</flux:button.group>
