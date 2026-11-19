@props(['field'])

{{-- A custom field in the dialog for changing selected tasks (x-bulk-edit-modal), as `bulkChoices` of EditsTasksInBulk describes it:
     left empty it stays as it is; a selection field offers to remove the value among its options, the other types a box below. --}}
@php($model = 'bulkFields.'.$field['id'])

@switch($field['type'])
    @case('select')
        <flux:select variant="listbox" wire:model="{{ $model }}" :label="$field['name']">
            <flux:select.option value="">{{ __('Leave unchanged') }}</flux:select.option>
            <flux:select.option value="none">{{ __('Remove value') }}</flux:select.option>
            @foreach ($field['options'] as $id => $name)
                <flux:select.option value="{{ $id }}">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
        @break
    @default
        <div class="space-y-2">
            @if ($field['type'] === 'date')
                <flux:date-picker wire:model="{{ $model }}" :label="$field['name']" locale="{{ app()->getLocale() }}" :placeholder="__('Leave unchanged')" clearable />
            @elseif ($field['type'] === 'number')
                <flux:input wire:model="{{ $model }}" type="number" step="any" :label="$field['name']" :placeholder="__('Leave unchanged')" />
            @else
                <flux:input wire:model="{{ $model }}" :label="$field['name']" :placeholder="__('Leave unchanged')" />
            @endif
            <flux:checkbox wire:model="bulkClearFields.{{ $field['id'] }}" :label="__('Remove value')" />
        </div>
@endswitch
