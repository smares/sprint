@props(['project', 'canEdit' => false])

{{-- The menu of the list with the CSV export and import (below the views on phones, in the toolbar on larger screens) --}}
<flux:dropdown align="end">
    <flux:button icon="ellipsis-horizontal" aria-label="{{ __('More actions') }}" tooltip="{{ __('More actions') }}" />

    <flux:menu>
        <flux:menu.item icon="arrow-down-tray" href="{{ route('projects.export', $project) }}">{{ __('Export as CSV') }}</flux:menu.item>
        <flux:menu.item icon="arrow-down-tray" href="{{ route('projects.export', [$project, 'delimiter' => 'semicolon']) }}">{{ __('Export as CSV for Excel') }}</flux:menu.item>
        @if ($canEdit)
            <flux:menu.separator />
            <flux:menu.item icon="arrow-up-tray" x-on:click="$flux.modal('project-import').show()">{{ __('Import CSV …') }}</flux:menu.item>
        @endif
    </flux:menu>
</flux:dropdown>
