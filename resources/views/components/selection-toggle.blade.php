{{-- The button that starts the selection of tasks in the views without boxes in their rows (see EditsTasksInBulk, ListsMyTasks): the
     text on large screens, only the symbol on phones. Put it where the page may not be selecting yet; it is gone while it is. --}}
<div {{ $attributes->class('shrink-0') }}>
    <flux:button icon="clipboard-document-check" square wire:click="startSelecting" class="sm:hidden max-sm:size-10!" aria-label="{{ __('Select') }}" tooltip="{{ __('Select') }}" />
    <flux:button icon="clipboard-document-check" wire:click="startSelecting" class="max-sm:hidden">{{ __('Select') }}</flux:button>
</div>
