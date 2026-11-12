@props(['count' => 0, 'name' => 'view-options'])

{{-- The small button of the phones that opens the dialog of x-view-options: the icon only. The number of settings in force is a small
     badge on its corner, so the button is always the same square (a wider one pushed the button beside it off the screen on narrow
     phones). No tooltip: on a touch screen it stays after the tap and covers the button next to it. --}}
@php($label = $count > 0 ? __('Display').', '.trans_choice('{1} :count setting|[2,*] :count settings', $count) : __('Display'))

<div {{ $attributes->class('sm:hidden') }}>
    <flux:modal.trigger :name="$name">
        <flux:button icon="adjustments-horizontal" square class="relative max-sm:size-10!" :aria-label="$label">
            @if ($count > 0)
                <flux:badge size="sm" color="blue" class="pointer-events-none absolute -end-1.5 -top-1.5 min-w-5 justify-center px-1!">{{ $count }}</flux:badge>
            @endif
        </flux:button>
    </flux:modal.trigger>
</div>
