@props(['views', 'label' => null])

{{-- @var array<string, array{href: string, icon: string, label: string, current: bool}> $views --}}

{{-- The views of the page the sidebar item above belongs to (a project, my tasks), with the open one marked. Expanded, the line at
     their start leads from the item's icon, so they hang on it like a tree. Collapsed, they stay as icons right under the item's
     tile, and the tile with its views is one light pill (see the wrapper in sidebar-projects and in the layout), so they do not look
     like further main entries (their names in the tooltip). --}}
<div {{ $attributes->class('-mt-1.5 ms-[1.35rem] border-s border-zinc-200 ps-2 in-data-flux-sidebar-collapsed-desktop:mt-0 in-data-flux-sidebar-collapsed-desktop:ms-0 in-data-flux-sidebar-collapsed-desktop:border-s-0 in-data-flux-sidebar-collapsed-desktop:ps-0 dark:border-zinc-700') }} data-sidebar-views role="group" @if ($label) aria-label="{{ __('Views of :name', ['name' => $label]) }}" @endif>
    @foreach ($views as $key => $view)
        <flux:sidebar.item wire:key="sidebar-view-{{ $key }}" :href="$view['href']" wire:navigate :icon="$view['icon']" :current="$view['current']" :aria-current="$view['current'] ? 'page' : null">
            {{ $view['label'] }}
        </flux:sidebar.item>
    @endforeach
</div>
