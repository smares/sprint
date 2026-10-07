@props(['task', 'field', 'showEmpty' => true, 'withName' => false])

@php
    $fieldValue = $task->fieldValues->firstWhere('custom_field_id', $field->id);
    $option = $fieldValue?->option_id ? $field->options->firstWhere('id', $fieldValue->option_id) : null;
    $text = match (true) {
        $fieldValue === null || $fieldValue->value === null => null,
        $field->type === \App\CustomFieldType::Date => \Illuminate\Support\Carbon::parse($fieldValue->value)->format('d.m.Y'),
        default => $fieldValue->value,
    };
@endphp

@if ($option)
    <x-color-badge size="sm" :color="$option->color">{{ $withName ? $field->name.': ' : '' }}{{ $option->name }}</x-color-badge>
@elseif ($text !== null)
    <flux:text size="sm" class="inline">{{ $withName ? $field->name.': ' : '' }}{{ $text }}</flux:text>
@elseif ($showEmpty)
    –
@endif
