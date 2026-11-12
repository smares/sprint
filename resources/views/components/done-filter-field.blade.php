@props(['options', 'model' => 'doneFilter'])

{{-- The choice of x-done-filter as a field, for the dialog of the phones (x-view-options) --}}
<flux:select wire:model.live="{{ $model }}" :label="__('Tasks')">
    @foreach ($options as $value => $label)
        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
    @endforeach
</flux:select>
