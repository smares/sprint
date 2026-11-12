@props(['count' => 0, 'name' => 'view-options'])

{{-- The small button of the phones that opens the dialog of x-view-options: the icon, and the number of settings in force as a badge
     in the button like the numbers elsewhere (the filters, the views). Compact, so that the row of the views, this button and the
     one that starts selecting still fit on a phone 360 px wide. No tooltip: on a touch screen it stays after the tap and covers the
     button next to it. --}}
@php($label = $count > 0 ? __('Display').', '.trans_choice('{1} :count setting|[2,*] :count settings', $count) : __('Display'))

<div {{ $attributes->class('sm:hidden') }}>
    <flux:modal.trigger :name="$name">
        @if ($count > 0)
            <flux:button icon="adjustments-horizontal" class="max-sm:h-10! max-sm:gap-1.5! max-sm:px-2.5!" :aria-label="$label"><flux:badge size="sm" color="blue" inset="top bottom" class="count-badge">{{ $count }}</flux:badge></flux:button>
        @else
            {{-- Without a number the button has no content at all, so that the icon stands in the middle of its square --}}
            <flux:button icon="adjustments-horizontal" square class="max-sm:size-10!" :aria-label="$label" />
        @endif
    </flux:modal.trigger>
</div>
