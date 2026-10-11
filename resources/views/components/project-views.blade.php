@props(['project', 'active'])

@php
    // In a group, Flux gives a filled first or last button one border fewer than an outlined one: without a transparent
    // stand-in the group gets a pixel narrower and the views shift when the first or last one is shown.
    $sameWidth = fn ($loop) => $loop->first ? 'border-s! border-s-transparent!' : ($loop->last ? 'border-e! border-e-transparent!' : '');
@endphp

{{-- Switching views without opening the sidebar, where it is only behind the menu button (below lg): icons on phones, with
     names from sm on. On large screens the sidebar lists the views itself. Two groups, because the button group rounds only
     its first and last child; the current view is filled, not greyed out. --}}
<div {{ $attributes->class('lg:hidden') }} data-view-switcher>
    <flux:button.group class="sm:hidden">
        @foreach (\App\Enums\ProjectView::cases() as $view)
            <flux:button :icon="$view->icon()" square :variant="$view->value === $active ? 'filled' : null" :class="$view->value === $active ? $sameWidth($loop) : ''" :aria-current="$view->value === $active ? 'page' : null" href="{{ route($view->routeName(), $project) }}" wire:navigate :aria-label="$view->label()" :tooltip="$view->label()" />
        @endforeach
    </flux:button.group>

    <flux:button.group class="max-sm:hidden">
        @foreach (\App\Enums\ProjectView::cases() as $view)
            <flux:button :icon="$view->icon()" :variant="$view->value === $active ? 'filled' : null" :class="$view->value === $active ? $sameWidth($loop) : ''" :aria-current="$view->value === $active ? 'page' : null" href="{{ route($view->routeName(), $project) }}" wire:navigate>{{ $view->label() }}</flux:button>
        @endforeach
    </flux:button.group>
</div>
