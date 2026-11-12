@props(['change' => true])

{{-- The bar at the bottom while tasks are selected: how many, and what to do with them (mark as done, change, delete). The page
     keeps the selection in `selected` (see EditsTasksInBulk and ListsMyTasks); `change` offers the dialog x-bulk-edit-modal. A slot named `extra`
     adds a button after the count. A page that sets `selecting` in the browser first (see the `instant` toggle) draws the bar always and shows it
     with x-show; ending sets `selecting` in the browser at once, too. Neither asks the server: the new state goes along with the
     next request (an action of the bar, the dialog), so switching back and forth costs no page to be drawn again. --}}
{{-- The bar has its place at the end of the page, within the content (centered over it, never under the sidebar), and sticks to the
     bottom edge of the window while the end of the page is further down. It takes room of its own there, so it covers nothing --}}
<div {{ $attributes->class('pointer-events-none sticky bottom-4 z-30 mt-auto shrink-0 pt-3') }} data-selection-bar>
    <div class="flex justify-center px-4">
        <div class="pointer-events-auto flex w-full max-w-xl flex-col gap-2 glass rounded-xl p-2 lg:w-auto lg:max-w-full lg:flex-row lg:items-center">
            <div class="flex items-center gap-2">
                <flux:text class="whitespace-nowrap px-2 font-medium" role="status">{!! __(':count selected', ['count' => '<span x-effect="$el.firstChild.data = $wire.selected.length">0</span>']) !!}</flux:text>

                @error('selected')
                    <flux:text class="text-red-600 dark:text-red-400" role="alert">{{ $message }}</flux:text>
                @enderror

                {{ $extra ?? '' }}

                <div class="ms-auto sm:hidden"><flux:button size="sm" variant="ghost" icon="x-mark" x-on:click="$wire.selecting = false; $wire.selected = []; $nextTick(() => [...document.querySelectorAll('[data-selection-toggle] button')].find((button) => button.offsetParent !== null)?.focus())" aria-label="{{ __('End selection') }}" tooltip="{{ __('End selection') }}" /></div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                {{-- Finish and open again are two sides of one action: a pair --}}
                <flux:button.group>
                    <flux:button size="sm" icon="check" wire:click="bulkComplete" x-bind:disabled="$wire.selected.length === 0">{{ __('Mark as done') }}</flux:button>
                    <flux:button size="sm" icon="arrow-uturn-left" wire:click="bulkReopen" x-bind:disabled="$wire.selected.length === 0">{{ __('Reopen') }}</flux:button>
                </flux:button.group>

                @if ($change)
                    {{-- The dialog is part of the page once the server knows about the selection (it asks for it here, the start of the selection
                         does not), and it opens empty; if the server cannot be reached, the person is told --}}
                    <flux:button size="sm" icon="pencil-square" x-bind:disabled="$wire.selected.length === 0" x-on:click="$wire.openBulkEdit().then(() => $flux.modal('bulk-edit').show()).catch(() => window.Flux.toast({ text: {{ \Illuminate\Support\Js::from(__('The dialog could not be opened. Please try again.')) }}, variant: 'danger' }))">{{ __('Change') }}</flux:button>
                @endif

                <flux:button size="sm" variant="danger" icon="trash" wire:click="bulkDelete" wire:confirm="{{ __('Permanently delete the selected tasks including their subtasks?') }}" x-bind:disabled="$wire.selected.length === 0">{{ __('Delete') }}</flux:button>

                <div class="max-sm:hidden"><flux:button size="sm" variant="ghost" icon="x-mark" x-on:click="$wire.selecting = false; $wire.selected = []; $nextTick(() => [...document.querySelectorAll('[data-selection-toggle] button')].find((button) => button.offsetParent !== null)?.focus())" aria-label="{{ __('End selection') }}" tooltip="{{ __('End selection') }}" /></div>
            </div>
        </div>
    </div>
</div>
