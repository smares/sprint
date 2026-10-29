@props(['project', 'active'])

<x-view-switcher :views="\App\Enums\ProjectView::links($project, \App\Enums\ProjectView::tryFrom($active))" {{ $attributes }}>{{ $slot }}</x-view-switcher>
