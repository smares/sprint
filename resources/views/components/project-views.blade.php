@props(['project', 'active'])

<flux:button.group>
    @foreach ([
        'list' => ['Liste', 'list-bullet', 'projects.show'],
        'board' => ['Board', 'view-columns', 'projects.board'],
        'calendar' => ['Kalender', 'calendar-days', 'projects.calendar'],
        'timeline' => ['Zeitleiste', 'arrow-turn-down-right', 'projects.timeline'],
    ] as $key => [$label, $icon, $route])
        @if ($key === $active)
            <flux:button :icon="$icon" square disabled aria-label="{{ $label }}" class="sm:hidden" />
            <flux:button :icon="$icon" disabled class="max-sm:hidden">{{ $label }}</flux:button>
        @else
            <flux:button :icon="$icon" square href="{{ route($route, $project) }}" wire:navigate aria-label="{{ $label }}" class="sm:hidden" />
            <flux:button :icon="$icon" href="{{ route($route, $project) }}" wire:navigate class="max-sm:hidden">{{ $label }}</flux:button>
        @endif
    @endforeach
</flux:button.group>
