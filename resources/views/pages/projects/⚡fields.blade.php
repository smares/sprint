<?php

use App\Color;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Project $project;

    public string $newName = '';

    public string $newType = 'select';

    /** @var array<int|string, string> */
    public array $names = [];

    /** @var array<int|string, bool> */
    public array $inList = [];

    /** @var array<int|string, string> */
    public array $optionNames = [];

    /** @var array<int|string, string> */
    public array $optionColors = [];

    /** @var array<int|string, string> */
    public array $newOptions = [];

    public string $deletingId = '';

    public function hydrate(): void
    {
        Gate::authorize('manage', $this->project);
    }

    public function mount(): void
    {
        Gate::authorize('manage', $this->project);

        $this->fillForm();
    }

    #[Computed]
    public function fields(): Collection
    {
        return $this->project->customFields()->with('options')->withCount('values')->get();
    }

    private function fillForm(): void
    {
        $this->names = $this->fields->pluck('name', 'id')->all();
        $this->inList = $this->fields->pluck('show_in_list', 'id')->all();
        $this->optionNames = $this->fields->flatMap->options->pluck('name', 'id')->all();
        $this->optionColors = $this->fields->flatMap->options->pluck('color', 'id')->all();
    }

    private function refresh(): void
    {
        unset($this->fields);
        $this->fillForm();
    }

    private function fieldOrFail(int|string $id): CustomField
    {
        return $this->project->customFields()->findOrFail($id);
    }

    private function optionOrFail(int|string $id): CustomFieldOption
    {
        return CustomFieldOption::whereIn('custom_field_id', $this->project->customFields()->select('id'))->findOrFail($id);
    }

    public function add(): void
    {
        $validated = $this->validate([
            'newName' => ['required', 'string', 'max:100'],
            'newType' => ['required', Rule::enum(CustomFieldType::class)],
        ], attributes: ['newName' => __('Name'), 'newType' => __('Type')]);

        $this->project->customFields()->create([
            'name' => trim($validated['newName']),
            'type' => $validated['newType'],
            'position' => CustomField::nextPositionIn($this->project->customFields()),
        ]);

        $this->reset('newName');
        $this->refresh();
    }

    public function updatedNames(string $value, string $id): void
    {
        $field = $this->fieldOrFail($id);
        $name = trim($value);

        if ($name === '' || mb_strlen($name) > 100) {
            $this->names[$id] = $field->name;

            return;
        }

        $field->update(['name' => $name]);
        $this->refresh();
    }

    public function updatedInList(bool $value, string $id): void
    {
        $this->fieldOrFail($id)->update(['show_in_list' => $value]);
        $this->refresh();
    }

    public function moveField(int|string $id, int $position): void
    {
        $this->fieldOrFail($id)->moveTo($position);

        $this->refresh();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = (string) $this->fieldOrFail($id)->id;
        Flux::modal('delete-field')->show();
    }

    public function delete(): void
    {
        $this->fieldOrFail($this->deletingId)->delete();

        $this->reset('deletingId');
        Flux::modal('delete-field')->close();
        $this->refresh();
    }

    public function addOption(int $fieldId): void
    {
        $field = $this->fieldOrFail($fieldId);
        abort_unless($field->type === CustomFieldType::Select, 404);

        $this->validate([
            "newOptions.$fieldId" => ['required', 'string', 'max:100'],
        ], attributes: ["newOptions.$fieldId" => __('Name')]);

        $field->options()->create([
            'name' => trim($this->newOptions[$fieldId]),
            'color' => Color::next($field->options()->count()),
            'position' => CustomFieldOption::nextPositionIn($field->options()),
        ]);

        unset($this->newOptions[$fieldId]);
        $this->refresh();
    }

    public function updatedOptionNames(string $value, string $id): void
    {
        $option = $this->optionOrFail($id);
        $name = trim($value);

        if ($name === '' || mb_strlen($name) > 100) {
            $this->optionNames[$id] = $option->name;

            return;
        }

        $option->update(['name' => $name]);
        $this->refresh();
    }

    public function updatedOptionColors(string $value, string $id): void
    {
        $option = $this->optionOrFail($id);

        if (! Color::isHex($value)) {
            $this->optionColors[$id] = $option->color;

            return;
        }

        $option->update(['color' => $value]);
        $this->refresh();
    }

    public function moveOption(int|string $id, int $position, int|string $fieldId): void
    {
        $option = $this->optionOrFail($id);
        abort_unless($option->custom_field_id === (int) $fieldId, 404);

        $option->moveTo($position);

        $this->refresh();
    }

    public function removeOption(int $id): void
    {
        $this->optionOrFail($id)->delete();

        $this->refresh();
    }

    public function rendering(View $view): void
    {
        $view->title(__('Fields – :project', ['project' => $this->project->name]));
    }
};
?>

<div class="max-w-3xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>{{ __('Projects') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item href="{{ route('projects.show', $project) }}" wire:navigate>{{ $project->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Fields') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl" class="mb-1">{{ __('Fields') }}</flux:heading>
    <flux:text class="mb-6">{!! __('Custom fields like <em>Priority</em> or <em>Effort</em> appear on every task in this project. Their order determines how they are displayed.') !!}</flux:text>

    <ul class="space-y-3" wire:sort="moveField">
        @foreach ($this->fields as $field)
            <li wire:key="field-{{ $field->id }}" wire:sort:item="{{ $field->id }}" class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <div class="flex items-center gap-3">
                    <flux:icon.bars-2 variant="micro" class="shrink-0 text-zinc-400" />
                    <flux:input size="sm" wire:model.blur="names.{{ $field->id }}" aria-label="{{ __('Name') }}" class="min-w-0 flex-1" />
                    <flux:badge size="sm">{{ $field->type->label() }}</flux:badge>
                    <flux:checkbox wire:model.live="inList.{{ $field->id }}" :label="__('In list')" />
                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $field->id }})" aria-label="{{ __('Delete field') }}" />
                </div>

                @if ($field->type === \App\Enums\CustomFieldType::Select)
                    <ul class="ms-6 space-y-2" wire:sort="moveOption" wire:sort:group="options" wire:sort:group-id="{{ $field->id }}">
                        @foreach ($field->options as $option)
                            <li wire:key="option-{{ $option->id }}" wire:sort:item="{{ $option->id }}" class="flex items-center gap-2">
                                <x-color-badge size="sm" :color="$option->color" class="shrink-0">&nbsp;</x-color-badge>
                                <flux:input size="sm" wire:model.blur="optionNames.{{ $option->id }}" aria-label="{{ __('Option') }}" class="min-w-0 flex-1" />
                                <flux:color-picker type="button" size="sm" with-confirmation :swatches="\App\Color::SWATCHES" wire:model.live="optionColors.{{ $option->id }}" aria-label="{{ __('Color') }}" />
                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeOption({{ $option->id }})" aria-label="{{ __('Remove option') }}" />
                            </li>
                        @endforeach
                    </ul>

                    <form wire:submit="addOption({{ $field->id }})" class="ms-6 flex items-center gap-2">
                        <flux:input size="sm" wire:model="newOptions.{{ $field->id }}" placeholder="{{ __('New option …') }}" class="max-w-xs" />
                        <flux:button size="sm" type="submit" icon="plus">{{ __('Add') }}</flux:button>
                    </form>
                    @error('newOptions.'.$field->id) <flux:text class="ms-6 text-red-500">{{ $message }}</flux:text> @enderror
                @endif
            </li>
        @endforeach
    </ul>

    <form wire:submit="add" class="mt-6 flex items-end gap-2">
        <flux:input wire:model="newName" :label="__('New field')" placeholder="{{ __('e.g. Effort') }}" class="min-w-0 flex-1" />
        <flux:select variant="listbox" wire:model="newType" :label="__('Type')" class="max-w-36">
            @foreach (\App\Enums\CustomFieldType::cases() as $type)
                <flux:select.option value="{{ $type->value }}">{{ $type->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button type="submit" icon="plus">{{ __('Create') }}</flux:button>
    </form>
    @error('newName') <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror

    <flux:modal name="delete-field" class="min-w-[22rem]">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Delete field?') }}</flux:heading>
            <flux:text>{{ __('The field and all values entered in it on tasks will be deleted.') }}</flux:text>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
