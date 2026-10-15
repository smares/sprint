@props(['task', 'field', 'showEmpty' => true, 'withName' => false])

@php
    $fieldValue = $task->fieldValues->firstWhere('custom_field_id', $field->id);
    $option = $fieldValue?->option_id ? $field->options->firstWhere('id', $fieldValue->option_id) : null;
    $text = $field->text($fieldValue?->value);

    // A date is stored as YYYY-MM-DD and reads in the format of the language.
    if ($text !== null && $field->type === \App\Enums\CustomFieldType::Date) {
        $text = \App\Services\DateService::parseIsoDate($text)?->isoFormat('L') ?? $text;
    }
@endphp

@if ($option)
    <x-color-badge size="sm" :color="$option->color">{{ $withName ? $field->name.': ' : '' }}{{ $option->name }}</x-color-badge>
@elseif ($text !== null)
    <flux:text size="sm" class="inline">{{ $withName ? $field->name.': ' : '' }}{{ $text }}</flux:text>
@elseif ($showEmpty)
    –
@endif
