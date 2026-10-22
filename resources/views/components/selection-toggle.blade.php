@props(['instant' => false])

{{-- The button that starts the selection of tasks in the views without boxes in their rows (see EditsTasksInBulk, ListsMyTasks): the
     text on large screens, only the symbol on phones. Put it where the page may not be selecting yet; it is gone while it is. The focus
     goes on to the first box, and back here when the selection ends (the button is drawn anew, so the focus would be lost).
     `instant`: for the pages that draw a (hidden) box in every row already (see data-select-box in the board): the selection starts in the
     browser at once and the server is only told afterwards, so the boxes do not wait for it to draw the whole page again. --}}
@php($start = $instant
    ? '$wire.selecting = true; $wire.$refresh().catch(() => { $wire.selecting = false }); $nextTick(() => document.querySelector(\'[data-select-box] ui-checkbox\')?.focus())'
    : '$wire.startSelecting().then(() => $nextTick(() => document.querySelector(\'[data-select-box] ui-checkbox\')?.focus()))')
<div {{ $attributes->class('shrink-0') }} data-selection-toggle>
    <flux:button icon="clipboard-document-check" square x-on:click="{{ $start }}" class="sm:hidden max-sm:size-10!" aria-label="{{ __('Select') }}" tooltip="{{ __('Select') }}" />
    <flux:button icon="clipboard-document-check" x-on:click="{{ $start }}" class="max-sm:hidden">{{ __('Select') }}</flux:button>
</div>
