@props(['count' => 0, 'name' => 'view-options'])

{{-- The small button of the phones that opens the dialog of x-view-options: the icon only, with the number of settings in force --}}
<div {{ $attributes->class('sm:hidden') }}>
    <flux:modal.trigger :name="$name">
        @if ($count > 0)
            <flux:button icon="adjustments-horizontal" class="max-sm:h-10!" :aria-label="__('Display')" :tooltip="__('Display')"><flux:badge size="sm" color="blue" inset="top bottom" class="count-badge">{{ $count }}</flux:badge></flux:button>
        @else
            {{-- Without a number the button has no content at all, so that the icon stands in the middle of its square --}}
            <flux:button icon="adjustments-horizontal" square class="max-sm:size-10!" :aria-label="__('Display')" :tooltip="__('Display')" />
        @endif
    </flux:modal.trigger>
</div>
