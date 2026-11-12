@props(['name' => 'view-options'])

{{-- On phones the controls that set how a view shows its tasks (sort order, finished tasks, saved views) take two rows of buttons
     above the content. They sit behind one small button (x-view-options-button, beside the one that starts selecting), and this is
     the dialog it opens: the slot holds the controls, as fields that fit on a phone. The toolbar the larger screens have stays as
     it is (the page hides it on phones). The dialog itself is not hidden on larger screens: one that is hidden but open (a phone
     turned to landscape) would block the page. --}}
<flux:modal :name="$name" class="w-full max-w-sm">
    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Display') }}</flux:heading>

        {{ $slot }}
    </div>
</flux:modal>
