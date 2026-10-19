@props(['change' => true])

{{-- The bar at the bottom while tasks are selected: how many, and what to do with them (mark as done, change, delete). The page
     keeps the selection in `selected` (see EditsTasksInBulk); `change` offers the dialog x-bulk-edit-modal. A slot named `extra`
     adds a button after the count. --}}
<div class="pointer-events-none fixed inset-x-0 bottom-4 z-30 flex justify-center px-4">
    <div class="pointer-events-auto flex w-full max-w-xl flex-col gap-2 rounded-xl border border-zinc-200 bg-white p-2 shadow-lg sm:w-auto sm:max-w-full sm:flex-row sm:items-center dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex items-center gap-2">
            <flux:text class="px-2 font-medium">{!! __(':count selected', ['count' => '<span x-text="$wire.selected.length">0</span>']) !!}</flux:text>

            {{ $extra ?? '' }}

            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="stopSelecting" aria-label="{{ __('End selection') }}" tooltip="{{ __('End selection') }}" class="ms-auto sm:hidden" />
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button size="sm" icon="check" wire:click="bulkComplete" x-bind:disabled="$wire.selected.length === 0">{{ __('Mark as done') }}</flux:button>

            @if ($change)
                <flux:modal.trigger name="bulk-edit">
                    <flux:button size="sm" icon="pencil-square" x-bind:disabled="$wire.selected.length === 0">{{ __('Change') }}</flux:button>
                </flux:modal.trigger>
            @endif

            <flux:button size="sm" variant="danger" icon="trash" wire:click="bulkDelete" wire:confirm="{{ __('Permanently delete the selected tasks including their subtasks?') }}" x-bind:disabled="$wire.selected.length === 0">{{ __('Delete') }}</flux:button>

            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="stopSelecting" aria-label="{{ __('End selection') }}" tooltip="{{ __('End selection') }}" class="max-sm:hidden" />
        </div>
    </div>
</div>
