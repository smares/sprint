@props(['active'])

@php
    // As the view switcher of a project: the current view in the accent color, icons only on phones; from lg on the sidebar has the views
    $current = 'bg-accent/10! text-accent-content! **:data-flux-icon:text-accent-content!';
    $views = [
        'list' => ['icon' => 'list-bullet', 'label' => __('List'), 'route' => 'tasks.mine'],
        'board' => ['icon' => 'view-columns', 'label' => __('Board'), 'route' => 'tasks.mine.board'],
    ];
@endphp

<div {{ $attributes->class('flex items-center justify-between gap-2 lg:hidden') }} data-view-switcher>
    <flux:button.group class="sm:hidden">
        @foreach ($views as $key => $view)
            <flux:button :icon="$view['icon']" square :class="$key === $active ? $current : ''" :aria-current="$key === $active ? 'page' : null" href="{{ route($view['route']) }}" wire:navigate :aria-label="$view['label']" :tooltip="$view['label']" />
        @endforeach
    </flux:button.group>

    <flux:button.group class="max-sm:hidden">
        @foreach ($views as $key => $view)
            <flux:button :icon="$view['icon']" :class="$key === $active ? $current : ''" :aria-current="$key === $active ? 'page' : null" href="{{ route($view['route']) }}" wire:navigate>{{ $view['label'] }}</flux:button>
        @endforeach
    </flux:button.group>

    {{ $slot }}
</div>
