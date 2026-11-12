@props(['count' => 0, 'name' => 'view-options'])

{{-- The small button of the phones that opens the dialog of x-view-options: the icon only, with the number of settings in force --}}
<div {{ $attributes->class('sm:hidden') }}>
    <flux:modal.trigger :name="$name">
        <flux:button icon="adjustments-horizontal" :square="$count === 0" class="max-sm:h-10!" :aria-label="__('Display')" :tooltip="__('Display')">
            @if ($count > 0)
                <flux:badge size="sm" color="blue" inset="top bottom" class="count-badge">{{ $count }}</flux:badge>
            @endif
        </flux:button>
    </flux:modal.trigger>
</div>
