@props(['views'])

{{-- @var array<string, array{href: string, icon: string, label: string, current: bool}> $views --}}

{{-- Switching views without opening the sidebar, where it is only behind the menu button (below lg): icons on phones, with
     names from sm on. On large screens the sidebar lists the views itself. Two groups, because the button group rounds only
     its first and last child; the current view is marked in orange like in the sidebar. The slot stands at the right end. --}}
@php
    // All buttons stay outlined, so the group keeps its width
    $current = 'bg-accent/10! text-accent-content! **:data-flux-icon:text-accent-content!';
@endphp

<div {{ $attributes->class('flex items-center justify-between gap-2 lg:hidden') }} data-view-switcher>
    <flux:button.group class="sm:hidden">
        @foreach ($views as $view)
            <flux:button :icon="$view['icon']" square :class="$view['current'] ? $current : ''" :aria-current="$view['current'] ? 'page' : null" :href="$view['href']" wire:navigate :aria-label="$view['label']" :tooltip="$view['label']" />
        @endforeach
    </flux:button.group>

    <flux:button.group class="max-sm:hidden">
        @foreach ($views as $view)
            <flux:button :icon="$view['icon']" :class="$view['current'] ? $current : ''" :aria-current="$view['current'] ? 'page' : null" :href="$view['href']" wire:navigate>{{ $view['label'] }}</flux:button>
        @endforeach
    </flux:button.group>

    {{ $slot }}
</div>
