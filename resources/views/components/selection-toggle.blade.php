@props(['instant' => false])

{{-- The button that starts the selection of tasks (see EditsTasksInBulk, ListsMyTasks): the text on large screens, only the symbol on
     phones. Put it where the page may not be selecting yet; it is gone while it is. The focus goes on to the first box that can be
     seen (not one in a collapsed column), and back here when the selection ends (the button is drawn anew, so the focus would be lost).
     `instant`: every page draws a (hidden) box in each of its rows already (see x-task-check-cell); the selection starts in the browser
     at once, and the server is told with the next request that needs it (the actions of the bar; the dialog asks for it, see
     x-selection-bar), so the boxes do not wait for it to draw the whole page again, and nothing is drawn again when one starts and
     ends the selection. --}}
@php($start = $instant
    ? '$wire.selecting = true; $wire.selected = []; $nextTick(() => [...document.querySelectorAll(\'[data-select-box] ui-checkbox\')].find((box) => (box.checkVisibility?.({ visibilityProperty: true }) ?? box.offsetParent !== null))?.focus())'
    : '$wire.startSelecting().then(() => $nextTick(() => [...document.querySelectorAll(\'[data-select-box] ui-checkbox\')].find((box) => (box.checkVisibility?.({ visibilityProperty: true }) ?? box.offsetParent !== null))?.focus()))')
<div {{ $attributes->class('shrink-0') }} data-selection-toggle>
    <div class="sm:hidden"><flux:button icon="clipboard-document-check" square x-on:click="{{ $start }}" class="max-sm:size-10!" aria-label="{{ __('Select') }}" tooltip="{{ __('Select') }}" /></div>
    <flux:button icon="clipboard-document-check" x-on:click="{{ $start }}" class="max-sm:hidden">{{ __('Select') }}</flux:button>
</div>
