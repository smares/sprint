@props(['count' => 0, 'summary' => null, 'name' => 'view-options'])

{{-- On phones the controls that set how a view shows its tasks (sort order, filter, finished tasks, saved views) take two rows of
     buttons above the content. They sit behind one button here, with the number of settings in force; the sheet holds the controls
     themselves (the slot). A line below the button says in words what is set (`summary`), when anything is. The toolbar the larger
     screens have stays as it is (the page hides it on phones). --}}
<div {{ $attributes->class('sm:hidden') }}>
    <flux:modal.trigger :name="$name">
        <flux:button icon="adjustments-horizontal">
            {{ __('Display') }}
            @if ($count > 0)
                <flux:badge size="sm" color="blue" inset="top bottom" class="count-badge">{{ $count }}</flux:badge>
            @endif
        </flux:button>
    </flux:modal.trigger>

    @if ($summary)
        <flux:text size="sm" class="mt-2 truncate" data-view-summary>{{ $summary }}</flux:text>
    @endif
</div>

<flux:modal :name="$name" flyout position="bottom" class="sm:hidden">
    <div class="space-y-6">
        <flux:heading size="lg">{{ __('Display') }}</flux:heading>

        {{ $slot }}
    </div>
</flux:modal>
