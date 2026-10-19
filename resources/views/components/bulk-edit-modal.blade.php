@props(['project'])

@php($tags = $project->tags()->orderBy('name')->get())

{{-- The dialog of the selection bar: what to change on all selected tasks at once; only what is filled in is changed. --}}
<flux:modal name="bulk-edit" class="w-full max-w-md">
    <form wire:submit="applyBulkChanges" class="space-y-5">
        <div>
            <flux:heading size="lg">{!! __('Change selected (:count)', ['count' => '<span x-text="$wire.selected.length">0</span>']) !!}</flux:heading>
            <flux:text class="mt-1">{{ __('Only what you fill in is changed; everything else stays as it is.') }}</flux:text>
        </div>

        <flux:select variant="listbox" wire:model="bulkStatus" :label="__('Status')">
            <flux:select.option value="">{{ __('Leave unchanged') }}</flux:select.option>
            @foreach ($project->statuses as $status)
                <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select variant="listbox" wire:model="bulkAssignee" :label="__('Assignee')">
            <flux:select.option value="">{{ __('Leave unchanged') }}</flux:select.option>
            <flux:select.option value="none">{{ __('Nobody') }}</flux:select.option>
            @foreach ($project->assignableUsers() as $user)
                <flux:select.option value="{{ $user->id }}">{{ $user->labelledName() }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="space-y-2">
            <flux:date-picker wire:model="bulkDueDate" :label="__('Due on')" locale="{{ app()->getLocale() }}" :placeholder="__('Leave unchanged')" clearable :description="__('If a task starts after that date, its start moves to this date.')" />
            <flux:checkbox wire:model="bulkClearDueDate" :label="__('Remove due date')" />
        </div>

        @if ($tags->isNotEmpty())
            <flux:pillbox wire:model="bulkAddTags" multiple :label="__('Add tags')" :placeholder="__('Select tags …')">
                @foreach ($tags as $tag)
                    <flux:pillbox.option wire:key="add-tag-{{ $tag->id }}" value="{{ $tag->id }}">{{ $tag->name }}</flux:pillbox.option>
                @endforeach
            </flux:pillbox>

            <flux:pillbox wire:model="bulkRemoveTags" multiple :label="__('Remove tags')" :placeholder="__('Select tags …')">
                @foreach ($tags as $tag)
                    <flux:pillbox.option wire:key="remove-tag-{{ $tag->id }}" value="{{ $tag->id }}">{{ $tag->name }}</flux:pillbox.option>
                @endforeach
            </flux:pillbox>
        @endif

        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary">{{ __('Apply') }}</flux:button>
        </div>
    </form>
</flux:modal>
