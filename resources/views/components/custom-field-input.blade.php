@props(['field' => null, 'model', 'placeholder' => '–', 'label' => null, 'size' => null])

{{-- The input for a value of a custom field, by its type: the same on the task and in the rules of an automation. Without a
     field (none chosen yet) it is a disabled input holding the place. --}}
@php($label ??= $field?->name ?? __('Value'))

@switch($field?->type)
    @case(null)
        <flux:input :size="$size" disabled :placeholder="$placeholder" :aria-label="$label" />
        @break
    @case(\App\Enums\CustomFieldType::Select)
        <flux:select :size="$size" variant="listbox" wire:model="{{ $model }}" :placeholder="$placeholder" clearable :aria-label="$label">
            @foreach ($field->options as $option)
                <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
        @break
    @case(\App\Enums\CustomFieldType::Number)
        <flux:input :size="$size" wire:model="{{ $model }}" type="number" step="any" :placeholder="$placeholder" :aria-label="$label" />
        @break
    @case(\App\Enums\CustomFieldType::Date)
        <flux:date-picker :size="$size" wire:model="{{ $model }}" locale="{{ app()->getLocale() }}" :placeholder="$placeholder" clearable :aria-label="$label" />
        @break
    @default
        <flux:input :size="$size" wire:model="{{ $model }}" :placeholder="$placeholder" :aria-label="$label" />
@endswitch
