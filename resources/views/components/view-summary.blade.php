@props(['parts' => []])

{{-- A line under the row of the views, on phones only (the toolbar of the larger screens says it with its buttons): what the view
     shows, in a few words, e.g. "Open tasks · Sorted by Due ↓". The small button beside the one that starts selecting only counts. --}}
@if (collect($parts)->filter()->isNotEmpty())
    <flux:text size="sm" class="-mt-3 mb-3 sm:hidden" data-view-summary>{{ collect($parts)->filter()->implode(' · ') }}</flux:text>
@endif
