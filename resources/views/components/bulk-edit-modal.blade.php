@props(['choices', 'moreFields' => false])

{{-- The dialog of the selection bar: what to change on all selected tasks at once; only what is filled in is changed. `choices` are the
     statuses, people and tags the page offers (`bulkChoices` of EditsTasksInBulk), as id => name, and the custom fields of the project:
     those the list shows come right away, the others folded away. Whether they are unfolded is `bulkMoreFields` of the page (`moreFields`),
     so a new render keeps them as they are, and an error in one of them unfolds them. --}}
@php($fields = collect($choices['fields'] ?? []))
@php($hiddenFields = $fields->reject(fn (array $field) => $field['in_list']))
<flux:modal name="bulk-edit" class="w-full max-w-md">
    <form wire:submit="applyBulkChanges" class="space-y-5">
        <div>
            <flux:heading size="lg">{!! __('Change selected (:count)', ['count' => '<span x-effect="$el.firstChild.data = $wire.selected.length">0</span>']) !!}</flux:heading>
            <flux:text class="mt-1">{{ __('Only what you fill in is changed; everything else stays as it is.') }}</flux:text>
        </div>

        <flux:select variant="listbox" wire:model="bulkStatus" :label="__('Status')">
            <flux:select.option value="">{{ __('Leave unchanged') }}</flux:select.option>
            @foreach ($choices['statuses'] as $id => $name)
                <flux:select.option value="{{ $id }}">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select variant="listbox" wire:model="bulkAssignee" :label="__('Assignee')">
            <flux:select.option value="">{{ __('Leave unchanged') }}</flux:select.option>
            <flux:select.option value="none">{{ __('Nobody') }}</flux:select.option>
            @foreach ($choices['users'] as $id => $name)
                <flux:select.option value="{{ $id }}">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="space-y-2">
            <flux:date-picker wire:model="bulkDueDate" :label="__('Due on')" locale="{{ app()->getLocale() }}" :placeholder="__('Leave unchanged')" clearable :description="__('If a task starts after that date, its start moves to this date.')" />
            <flux:checkbox wire:model="bulkClearDueDate" :label="__('Remove due date')" />
        </div>

        @foreach ($fields->filter(fn (array $field) => $field['in_list']) as $field)
            <x-bulk-edit-field :field="$field" />
        @endforeach

        @if ($hiddenFields->isNotEmpty())
            <flux:accordion transition>
                <flux:accordion.item wire:model="bulkMoreFields" :expanded="$moreFields">
                    <flux:accordion.heading>{{ __('More fields (:count)', ['count' => $hiddenFields->count()]) }}</flux:accordion.heading>
                    <flux:accordion.content>
                        <div class="space-y-5 pt-2">
                            @foreach ($hiddenFields as $field)
                                <x-bulk-edit-field :field="$field" />
                            @endforeach
                        </div>
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
        @endif

        @if ($choices['tags'] !== [])
            <flux:pillbox wire:model="bulkAddTags" multiple :label="__('Add tags')" :placeholder="__('Select tags …')">
                @foreach ($choices['tags'] as $id => $name)
                    <flux:pillbox.option wire:key="add-tag-{{ $id }}" value="{{ $id }}">{{ $name }}</flux:pillbox.option>
                @endforeach
            </flux:pillbox>

            <flux:pillbox wire:model="bulkRemoveTags" multiple :label="__('Remove tags')" :placeholder="__('Select tags …')">
                @foreach ($choices['tags'] as $id => $name)
                    <flux:pillbox.option wire:key="remove-tag-{{ $id }}" value="{{ $id }}">{{ $name }}</flux:pillbox.option>
                @endforeach
            </flux:pillbox>
        @endif

        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary">{{ __('Apply') }}</flux:button>
        </div>
    </form>
</flux:modal>
