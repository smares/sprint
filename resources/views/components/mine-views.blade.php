@props(['active'])

<x-view-switcher :views="\App\Enums\MineView::links(\App\Enums\MineView::tryFrom($active))" {{ $attributes }}>{{ $slot }}</x-view-switcher>
