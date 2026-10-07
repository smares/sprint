@props(['project', 'active'])

<flux:button.group>
    @foreach ([
        'list' => ['Liste', 'list-bullet', 'projects.show'],
        'board' => ['Board', 'view-columns', 'projects.board'],
        'calendar' => ['Kalender', 'calendar-days', 'projects.calendar'],
        'timeline' => ['Zeitleiste', 'chart-bar', 'projects.timeline'],
    ] as $key => [$label, $icon, $route])
        @if ($key === $active)
            <flux:button :icon="$icon" disabled>{{ $label }}</flux:button>
        @else
            <flux:button :icon="$icon" href="{{ route($route, $project) }}" wire:navigate>{{ $label }}</flux:button>
        @endif
    @endforeach
</flux:button.group>
