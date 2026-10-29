@props(['views'])

{{-- @var array<string, array{href: string, icon: string, label: string, current: bool}> $views --}}

{{-- The views of the page the sidebar item above belongs to (a project, my tasks), with the open one marked. Collapsed, they stay
     as icons right under the item's tile (their names in the tooltip). --}}
<div {{ $attributes->class('ms-5 border-s border-zinc-200 ps-2 in-data-flux-sidebar-collapsed-desktop:ms-0 in-data-flux-sidebar-collapsed-desktop:mb-2 in-data-flux-sidebar-collapsed-desktop:border-s-0 in-data-flux-sidebar-collapsed-desktop:ps-0 dark:border-zinc-700') }} data-sidebar-views>
    @foreach ($views as $key => $view)
        <flux:sidebar.item wire:key="sidebar-view-{{ $key }}" :href="$view['href']" wire:navigate :icon="$view['icon']" :current="$view['current']" :aria-current="$view['current'] ? 'page' : null">
            {{ $view['label'] }}
        </flux:sidebar.item>
    @endforeach
</div>
