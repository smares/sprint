@props(['column', 'name'])

{{-- The button in the header of a board column that collapses it to a strip and opens it again. It needs `collapsed` and
     `toggle()` of the board it is in (Alpine). The name is in the label because the page has one of these for every column. --}}
<flux:tooltip>
    <flux:button size="xs" variant="ghost" square class="max-sm:size-10!" x-on:click.stop="toggle({{ $column }})" data-column-collapse
        x-bind:aria-expanded="! collapsed.includes({{ $column }})"
        x-bind:aria-label="collapsed.includes({{ $column }}) ? {{ \Illuminate\Support\Js::from(__('Expand column :name', ['name' => $name])) }} : {{ \Illuminate\Support\Js::from(__('Collapse column :name', ['name' => $name])) }}">
        <flux:icon.chevron-double-left variant="micro" x-show="! collapsed.includes({{ $column }})" />
        <flux:icon.chevron-double-right variant="micro" x-show="collapsed.includes({{ $column }})" x-cloak />
    </flux:button>
    <flux:tooltip.content>
        <span x-show="! collapsed.includes({{ $column }})">{{ __('Collapse column') }}</span>
        <span x-show="collapsed.includes({{ $column }})" x-cloak>{{ __('Expand column') }}</span>
    </flux:tooltip.content>
</flux:tooltip>
