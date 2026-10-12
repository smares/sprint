@props(['project', 'active'])

@php
    // The current view in the accent color, as in the sidebar; all buttons stay outlined, so the group keeps its width
    $current = 'bg-accent/10! text-accent-content! **:data-flux-icon:text-accent-content!';
@endphp

{{-- Switching views without opening the sidebar, where it is only behind the menu button (below lg): icons on phones, with
     names from sm on. On large screens the sidebar lists the views itself. Two groups, because the button group rounds only
     its first and last child; the current view is marked in orange like in the sidebar. --}}
<div {{ $attributes->class('lg:hidden') }} data-view-switcher>
    <flux:button.group class="sm:hidden">
        @foreach (\App\Enums\ProjectView::cases() as $view)
            <flux:button :icon="$view->icon()" square :class="$view->value === $active ? $current : ''" :aria-current="$view->value === $active ? 'page' : null" href="{{ route($view->routeName(), $project) }}" wire:navigate :aria-label="$view->label()" :tooltip="$view->label()" />
        @endforeach
    </flux:button.group>

    <flux:button.group class="max-sm:hidden">
        @foreach (\App\Enums\ProjectView::cases() as $view)
            <flux:button :icon="$view->icon()" :class="$view->value === $active ? $current : ''" :aria-current="$view->value === $active ? 'page' : null" href="{{ route($view->routeName(), $project) }}" wire:navigate>{{ $view->label() }}</flux:button>
        @endforeach
    </flux:button.group>
</div>
