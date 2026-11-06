@props(['options', 'current', 'model' => 'doneFilter'])

{{-- What a view does with finished tasks, as a menu in the toolbar like the one for the sort order: open tasks only, all of them
     or the finished ones only. The page keeps the choice in the property named by `model` (see App\Concerns\FiltersDoneTasks). --}}
<flux:dropdown align="start">
    <flux:button icon="check-circle" aria-label="{{ __('Tasks') }}: {{ $options[$current] ?? $options['all'] }}">{{ $options[$current] ?? $options['all'] }}</flux:button>

    <flux:menu>
        <flux:menu.radio.group wire:model.live="{{ $model }}">
            @foreach ($options as $value => $label)
                <flux:menu.radio value="{{ $value }}">{{ $label }}</flux:menu.radio>
            @endforeach
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
