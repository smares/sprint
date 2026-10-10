<?php

use App\Enums\AutomationAction;
use App\Enums\AutomationTrigger;
use App\Enums\CustomFieldType;
use App\Models\Automation;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** The most actions one rule may have. */
    private const MAX_ACTIONS = 8;

    public Project $project;

    public ?int $editingId = null;

    public string $name = '';

    public string $trigger = 'status_changed';

    public string $triggerValue = '';

    /** For a field trigger: the value it waits for (an option id, a number, a date or a text); empty for any value. */
    public string $triggerFieldValue = '';

    public string $conditionStatus = '';

    public string $conditionAssignee = '';

    public string $conditionTag = '';

    /** @var list<array{type: string, value: string, field: string}> `field` is only used by "set a field". */
    public array $actions = [];

    public string $deletingId = '';

    public function hydrate(): void
    {
        Gate::authorize('manage', $this->project);
    }

    public function mount(): void
    {
        Gate::authorize('manage', $this->project);
    }

    /**
     * @return Collection<int, Automation>
     */
    #[Computed]
    public function automations(): Collection
    {
        return $this->project->automations()->with('creator')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function statusNames(): array
    {
        return $this->project->statuses()->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function tagNames(): array
    {
        return $this->project->tags()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return Collection<int, CustomField>
     */
    #[Computed]
    public function fields(): Collection
    {
        return $this->project->customFields()->with('options')->get()->keyBy('id');
    }

    /**
     * The field chosen for a field trigger, if any.
     */
    protected function triggerField(): ?CustomField
    {
        return $this->trigger === AutomationTrigger::FieldSet->value ? $this->fields->get((int) $this->triggerValue) : null;
    }

    /**
     * What the trigger can be narrowed down to, as id => label.
     *
     * @return array<int, string>
     */
    protected function triggerChoices(): array
    {
        return match (AutomationTrigger::tryFrom($this->trigger)) {
            AutomationTrigger::StatusChanged => $this->statusNames,
            AutomationTrigger::TagAdded => $this->tagNames,
            AutomationTrigger::FieldSet => $this->fields->map(fn (CustomField $field) => $field->name)->all(),
            default => $this->userNames,
        };
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function userNames(): array
    {
        return $this->project->eligibleUsers()->orderBy('name')->get()->mapWithKeys(fn (User $user) => [$user->id => $user->labelledName()])->all();
    }

    public function openForm(?int $id = null): void
    {
        $this->resetErrorBag();
        $rule = $id === null ? null : $this->ruleOrFail($id);

        $this->editingId = $rule?->id;
        $this->name = $rule->name ?? '';
        $this->trigger = ($rule->trigger ?? AutomationTrigger::StatusChanged)->value;
        $this->triggerValue = (string) ($rule?->trigger_value ?? '');
        $this->triggerFieldValue = $rule->trigger_field_value ?? '';
        $this->conditionStatus = (string) ($rule?->conditions['status_id'] ?? '');
        $this->conditionAssignee = (string) ($rule?->conditions['assignee_id'] ?? '');
        $this->conditionTag = (string) ($rule?->conditions['tag_id'] ?? '');
        $this->actions = $rule === null
            ? [['type' => '', 'value' => '', 'field' => '']]
            : array_map(fn (array $step) => is_array($step['value'])
                ? ['type' => $step['type']->value, 'value' => (string) ($step['value']['value'] ?? ''), 'field' => (string) ($step['value']['field'] ?? '')]
                : ['type' => $step['type']->value, 'value' => (string) ($step['value'] ?? ''), 'field' => ''], $rule->steps());

        Flux::modal('automation-form')->show();
    }

    public function updatedTrigger(): void
    {
        $this->triggerValue = '';
        $this->triggerFieldValue = '';
    }

    public function updatedTriggerValue(): void
    {
        $this->triggerFieldValue = '';
    }

    public function updatedActions(mixed $value, ?string $key = null): void
    {
        if ($key !== null && str_ends_with($key, '.type')) {
            $this->actions[(int) $key]['value'] = '';
            $this->actions[(int) $key]['field'] = '';
        }

        if ($key !== null && str_ends_with($key, '.field')) {
            $this->actions[(int) $key]['value'] = '';
        }
    }

    public function addAction(): void
    {
        if (count($this->actions) < self::MAX_ACTIONS) {
            $this->actions[] = ['type' => '', 'value' => '', 'field' => ''];
        }
    }

    public function removeAction(int $index): void
    {
        unset($this->actions[$index]);
        $this->actions = array_values($this->actions);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'trigger' => ['required', Rule::enum(AutomationTrigger::class)],
            'actions' => ['required', 'array', 'min:1', 'max:'.self::MAX_ACTIONS],
            'actions.*.type' => ['required', Rule::enum(AutomationAction::class)],
        ], attributes: ['name' => __('Name'), 'trigger' => __('When'), 'actions' => __('Then'), 'actions.*.type' => __('Action')]);

        $trigger = AutomationTrigger::from($validated['trigger']);
        $triggerValue = $this->idOf('triggerValue', array_keys($this->triggerChoices()), $trigger->valueIsOptional());
        $triggerFieldValue = $this->fieldValue($this->triggerField(), trim($this->triggerFieldValue));
        $conditions = array_filter([
            'status_id' => $this->idOf('conditionStatus', array_keys($this->statusNames), true),
            'assignee_id' => $this->idOf('conditionAssignee', array_keys($this->userNames), true),
            'tag_id' => $this->idOf('conditionTag', array_keys($this->tagNames), true),
        ]);

        $steps = [];

        foreach ($this->actions as $index => $action) {
            $steps[] = ['type' => $action['type'], 'value' => $this->actionValue($index, AutomationAction::from($action['type']), trim($action['value']), $trigger === AutomationTrigger::FieldSet ? $triggerValue : null)];
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $attributes = [
            'name' => trim($validated['name']),
            'trigger' => $trigger,
            'trigger_value' => $triggerValue,
            'trigger_field_value' => $triggerFieldValue,
            'conditions' => $conditions === [] ? null : $conditions,
            'actions' => $steps,
            'created_by' => auth()->id(),
        ];

        if ($this->editingId === null) {
            $this->project->automations()->create($attributes);
        } else {
            $this->ruleOrFail($this->editingId)->update($attributes);
        }

        unset($this->automations);
        Flux::modal('automation-form')->close();
    }

    public function toggle(int $id): void
    {
        $rule = $this->ruleOrFail($id);

        $rule->update($rule->enabled ? ['enabled' => false] : ['enabled' => true, 'created_by' => auth()->id()]);
        unset($this->automations);
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = (string) $this->ruleOrFail($id)->id;
        Flux::modal('delete-automation')->show();
    }

    public function delete(): void
    {
        $this->ruleOrFail($this->deletingId)->delete();

        $this->reset('deletingId');
        Flux::modal('delete-automation')->close();
        unset($this->automations);
    }

    private function ruleOrFail(int|string $id): Automation
    {
        return $this->project->automations()->findOrFail($id);
    }

    /**
     * The chosen id of a select, or null when left empty (if allowed); anything not offered is an error.
     *
     * @param  list<int|string>  $allowed
     */
    private function idOf(string $property, array $allowed, bool $optional): ?int
    {
        $value = trim((string) data_get($this, $property, ''));

        if ($value === '') {
            if (! $optional) {
                $this->addError($property, __('Please choose a value.'));
            }

            return null;
        }

        if (! in_array((int) $value, array_map(intval(...), $allowed), true)) {
            $this->addError($property, __('Please choose a value.'));

            return null;
        }

        return (int) $value;
    }

    /**
     * The value a field trigger waits for, stored like the field's values; null for any value (or no field trigger).
     */
    private function fieldValue(?CustomField $field, string $value): ?string
    {
        if (! $field instanceof CustomField || $value === '') {
            return null;
        }

        $stored = $field->storedFromInput($value);

        if ($stored === null) {
            $this->addError('triggerFieldValue', __('This value does not fit the field.'));
        }

        return $stored;
    }

    /**
     * @param  ?int  $triggerFieldId  The field the rule reacts to, which it must not set itself.
     * @return int|string|array{field: int, value: ?string}|null
     */
    private function actionValue(int $index, AutomationAction $action, string $value, ?int $triggerFieldId): int|string|array|null
    {
        $key = "actions.$index.value";

        return match ($action) {
            AutomationAction::SetAssignee => $value === '' ? null : $this->idOf($key, array_keys($this->userNames), false),
            AutomationAction::Notify => $this->idOf($key, array_keys($this->userNames), false),
            AutomationAction::SetStatus => $this->idOf($key, array_keys($this->statusNames), false),
            AutomationAction::AddTag => $this->idOf($key, array_keys($this->tagNames), false),
            AutomationAction::ShiftDueDate => $this->days($key, $value),
            AutomationAction::Comment => $this->text($key, $value),
            AutomationAction::SetField => $this->fieldStep($index, $value, $triggerFieldId),
        };
    }

    /**
     * The field to set and its value (empty clears it).
     *
     * @return array{field: int, value: ?string}|null
     */
    private function fieldStep(int $index, string $value, ?int $triggerFieldId): ?array
    {
        $fieldId = $this->idOf("actions.$index.field", $this->fields->keys()->all(), false);

        if ($fieldId === null) {
            return null;
        }

        if ($fieldId === $triggerFieldId) {
            $this->addError("actions.$index.field", __('A rule cannot set the field it reacts to.'));

            return null;
        }

        if ($value === '') {
            return ['field' => $fieldId, 'value' => null];
        }

        $stored = $this->fields->get($fieldId)?->storedFromInput($value);

        if ($stored === null) {
            $this->addError("actions.$index.value", __('This value does not fit the field.'));

            return null;
        }

        return ['field' => $fieldId, 'value' => $stored];
    }

    private function days(string $key, string $value): ?int
    {
        if (! preg_match('/^-?\d{1,3}$/', $value) || (int) $value === 0 || abs((int) $value) > 365) {
            $this->addError($key, __('Enter a number of days between -365 and 365, not 0.'));

            return null;
        }

        return (int) $value;
    }

    private function text(string $key, string $value): ?string
    {
        if ($value === '' || mb_strlen($value) > 2000) {
            $this->addError($key, __('The text must not be empty and may have at most :max characters.', ['max' => 2000]));

            return null;
        }

        return $value;
    }

    /**
     * The rule as one sentence for the list.
     */
    protected function triggerSentence(Automation $rule): string
    {
        $name = fn (array $names) => $names[$rule->trigger_value] ?? '–';

        return match ($rule->trigger) {
            AutomationTrigger::StatusChanged => __('When the status changes to “:name”', ['name' => $name($this->statusNames)]),
            AutomationTrigger::TagAdded => __('When the tag “:name” is added', ['name' => $name($this->tagNames)]),
            AutomationTrigger::FieldSet => $rule->trigger_field_value === null
                ? __('When “:field” gets a value', ['field' => $this->fields->get((int) $rule->trigger_value)->name ?? '–'])
                : __('When “:field” is set to “:value”', [
                    'field' => $this->fields->get((int) $rule->trigger_value)->name ?? '–',
                    'value' => $this->fields->get((int) $rule->trigger_value)?->text($rule->trigger_field_value) ?? '–',
                ]),
            AutomationTrigger::AssigneeChanged => $rule->trigger_value === null
                ? __('When the assignee changes')
                : __('When the assignee changes to :name', ['name' => $name($this->userNames)]),
        };
    }

    /**
     * @return list<string>
     */
    protected function conditionSentences(Automation $rule): array
    {
        $conditions = $rule->conditions ?? [];

        return array_values(array_filter([
            isset($conditions['status_id']) ? __('the status is “:name”', ['name' => $this->statusNames[$conditions['status_id']] ?? '–']) : null,
            isset($conditions['assignee_id']) ? __('the assignee is :name', ['name' => $this->userNames[$conditions['assignee_id']] ?? '–']) : null,
            isset($conditions['tag_id']) ? __('the task has the tag “:name”', ['name' => $this->tagNames[$conditions['tag_id']] ?? '–']) : null,
        ]));
    }

    /**
     * @return list<string>
     */
    protected function actionSentences(Automation $rule): array
    {
        return array_map(fn (array $step) => match ($step['type']) {
            AutomationAction::SetAssignee => $step['value'] === null ? __('Remove the assignee') : __('Set the assignee to :name', ['name' => $this->userNames[$step['value']] ?? '–']),
            AutomationAction::SetStatus => __('Set the status to “:name”', ['name' => $this->statusNames[$step['value']] ?? '–']),
            AutomationAction::AddTag => __('Add the tag “:name”', ['name' => $this->tagNames[$step['value']] ?? '–']),
            AutomationAction::ShiftDueDate => trans_choice('Move the due date by :count day|Move the due date by :count days', abs((int) $step['value']), ['count' => (int) $step['value']]),
            AutomationAction::Comment => __('Comment: “:text”', ['text' => Str::limit((string) $step['value'], 80)]),
            AutomationAction::Notify => __('Notify :name', ['name' => $this->userNames[$step['value']] ?? '–']),
            AutomationAction::SetField => $this->fieldStepSentence((array) $step['value']),
        }, $rule->steps());
    }

    /**
     * @param  array{field?: mixed, value?: mixed}  $step
     */
    private function fieldStepSentence(array $step): string
    {
        $field = $this->fields->get((int) ($step['field'] ?? 0));

        return ($step['value'] ?? null) === null
            ? __('Clear “:field”', ['field' => $field->name ?? '–'])
            : __('Set “:field” to “:value”', ['field' => $field->name ?? '–', 'value' => $field?->text((string) $step['value']) ?? '–']);
    }

    public function rendering(View $view): void
    {
        $view->title(__('Automations – :project', ['project' => $this->project->name]));
    }
};
?>

<div class="max-w-4xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>{{ __('Projects') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item href="{{ route('projects.show', $project) }}" wire:navigate>{{ $project->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Automations') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start">
        <div class="min-w-0 flex-1">
            <flux:heading size="xl" class="mb-1">{{ __('Automations') }}</flux:heading>
            <flux:text>{{ __('A rule does something on a task as soon as it changes, for example set the assignee when the status becomes “Done”. Changes made by a rule are shown in the history under the rule’s name; rules do not trigger each other.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="openForm">{{ __('New automation') }}</flux:button>
    </div>

    @if ($this->automations->isEmpty())
        <flux:text>{{ __('No automations yet.') }}</flux:text>
    @else
        <ul class="space-y-3">
            @foreach ($this->automations as $rule)
                <li wire:key="automation-{{ $rule->id }}" @class(['rounded-lg border border-zinc-200 p-3 dark:border-zinc-700', 'opacity-60' => ! $rule->enabled])>
                    <div class="flex items-center gap-3">
                        <flux:icon.bolt variant="micro" class="shrink-0 text-zinc-400" />
                        <flux:heading class="min-w-0 flex-1 truncate">{{ $rule->name }}</flux:heading>
                        @unless ($rule->enabled)
                            <flux:badge size="sm">{{ __('Off') }}</flux:badge>
                        @endunless
                        <flux:switch wire:click="toggle({{ $rule->id }})" :checked="$rule->enabled" aria-label="{{ __('Turn “:name” on or off', ['name' => $rule->name]) }}" />
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openForm({{ $rule->id }})" aria-label="{{ __('Edit automation') }}" tooltip="{{ __('Edit automation') }}" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $rule->id }})" aria-label="{{ __('Delete automation') }}" tooltip="{{ __('Delete automation') }}" />
                    </div>

                    <div class="ms-7 mt-2 space-y-1">
                        <flux:text size="sm"><strong class="font-medium">{{ $this->triggerSentence($rule) }}</strong>@if ($conditions = $this->conditionSentences($rule)), {{ __('and') }} {{ implode(' '.__('and').' ', $conditions) }}@endif:</flux:text>
                        <ul class="list-inside list-disc text-sm text-zinc-600 dark:text-zinc-300">
                            @foreach ($this->actionSentences($rule) as $sentence)
                                <li>{{ $sentence }}</li>
                            @endforeach
                        </ul>
                        <flux:text size="sm" class="text-zinc-500">{{ $rule->creator ? __('Acts with the rights of :name', ['name' => $rule->creator->name]) : __('Has no author any more') }}</flux:text>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <flux:modal name="automation-form" class="w-full max-w-2xl">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId === null ? __('New automation') : __('Edit automation') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" placeholder="{{ __('e.g. Hand over when done') }}" />

            <div class="space-y-3">
                <flux:heading>{{ __('When') }}</flux:heading>
                {{-- A field trigger takes three things in a row: the trigger, the field and the value --}}
                <div @class(['grid gap-3', 'sm:grid-cols-3' => $trigger === 'field_set', 'sm:grid-cols-2' => $trigger !== 'field_set'])>
                    <flux:select variant="listbox" wire:model.live="trigger" aria-label="{{ __('When') }}">
                        @foreach (\App\Enums\AutomationTrigger::cases() as $case)
                            <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select variant="listbox" wire:model.live="triggerValue" :placeholder="match ($trigger) { 'assignee_changed' => __('Anyone'), 'field_set' => __('Choose a field …'), default => __('Choose …') }" clearable aria-label="{{ $trigger === 'field_set' ? __('Field') : __('Value') }}">
                        @foreach ($this->triggerChoices() as $id => $label)
                            <flux:select.option value="{{ $id }}" wire:key="trigger-{{ $trigger }}-{{ $id }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    {{-- The value, entered like on the task; left empty, any value counts. Until a field is chosen the column stays empty. --}}
                    @if ($trigger === 'field_set')
                        <div wire:key="trigger-field-{{ $this->triggerField()?->id }}">
                            @switch($this->triggerField()?->type)
                                @case(null)
                                    <flux:input disabled :placeholder="__('Any value')" aria-label="{{ __('Value') }}" />
                                    @break
                                @case(\App\Enums\CustomFieldType::Select)
                                    <flux:select variant="listbox" wire:model="triggerFieldValue" :placeholder="__('Any value')" clearable aria-label="{{ __('Value') }}">
                                        @foreach ($this->triggerField()->options as $option)
                                            <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @break
                                @case(\App\Enums\CustomFieldType::Number)
                                    <flux:input wire:model="triggerFieldValue" type="number" step="any" :placeholder="__('Any value')" aria-label="{{ __('Value') }}" />
                                    @break
                                @case(\App\Enums\CustomFieldType::Date)
                                    <flux:date-picker wire:model="triggerFieldValue" locale="{{ app()->getLocale() }}" :placeholder="__('Any value')" clearable aria-label="{{ __('Value') }}" />
                                    @break
                                @default
                                    <flux:input wire:model="triggerFieldValue" :placeholder="__('Any value')" aria-label="{{ __('Value') }}" />
                            @endswitch
                        </div>
                    @endif
                </div>
                @if ($this->triggerField()?->type === \App\Enums\CustomFieldType::Text)
                    <flux:text size="sm" class="text-zinc-500">{{ __('Upper and lower case do not matter.') }}</flux:text>
                @endif
                <flux:error name="triggerValue" />
                <flux:error name="triggerFieldValue" />
            </div>

            <div class="space-y-3">
                <flux:heading>{{ __('Only if (optional)') }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-3">
                    <flux:select variant="listbox" wire:model="conditionStatus" :label="__('Status')" :placeholder="__('Any')" clearable>
                        @foreach ($this->statusNames as $id => $label)
                            <flux:select.option value="{{ $id }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select variant="listbox" wire:model="conditionAssignee" :label="__('Assignee')" :placeholder="__('Anyone')" clearable>
                        @foreach ($this->userNames as $id => $label)
                            <flux:select.option value="{{ $id }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select variant="listbox" wire:model="conditionTag" :label="__('Tag')" :placeholder="__('Any')" clearable>
                        @foreach ($this->tagNames as $id => $label)
                            <flux:select.option value="{{ $id }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:error name="conditionStatus" />
                <flux:error name="conditionAssignee" />
                <flux:error name="conditionTag" />
            </div>

            <div class="space-y-3">
                <flux:heading>{{ __('Then') }}</flux:heading>
                @foreach ($actions as $index => $action)
                    <div class="space-y-1" wire:key="action-{{ $index }}-{{ $action['type'] }}">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                            <div class="sm:w-60 sm:shrink-0">
                                <flux:select variant="listbox" wire:model.live="actions.{{ $index }}.type" :placeholder="__('Choose an action …')" aria-label="{{ __('Action') }}">
                                    @foreach (\App\Enums\AutomationAction::cases() as $case)
                                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>

                            <div class="min-w-0 flex-1">
                                @switch($action['type'])
                                    @case('')
                                        @break
                                    @case('set_assignee')
                                    @case('notify')
                                        <flux:select variant="listbox" wire:model="actions.{{ $index }}.value" :placeholder="$action['type'] === 'notify' ? __('Choose …') : __('Nobody (remove the assignee)')" clearable aria-label="{{ __('Value') }}">
                                            @foreach ($this->userNames as $id => $label)
                                                <flux:select.option value="{{ $id }}">{{ $label }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        @break
                                    @case('set_status')
                                        <flux:select variant="listbox" wire:model="actions.{{ $index }}.value" :placeholder="__('Choose …')" aria-label="{{ __('Value') }}">
                                            @foreach ($this->statusNames as $id => $label)
                                                <flux:select.option value="{{ $id }}">{{ $label }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        @break
                                    @case('add_tag')
                                        <flux:select variant="listbox" wire:model="actions.{{ $index }}.value" :placeholder="__('Choose …')" aria-label="{{ __('Value') }}">
                                            @foreach ($this->tagNames as $id => $label)
                                                <flux:select.option value="{{ $id }}">{{ $label }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        @break
                                    @case('set_field')
                                        {{-- The field, then its value entered like on the task; left empty, the field is cleared --}}
                                        @php($actionField = $this->fields->get((int) $action['field']))
                                        <div class="grid gap-2 sm:grid-cols-2">
                                            <flux:select variant="listbox" wire:model.live="actions.{{ $index }}.field" :placeholder="__('Choose a field …')" aria-label="{{ __('Field') }}">
                                                @foreach ($this->fields as $field)
                                                    @if ($trigger !== 'field_set' || (string) $field->id !== $triggerValue)
                                                        <flux:select.option value="{{ $field->id }}">{{ $field->name }}</flux:select.option>
                                                    @endif
                                                @endforeach
                                            </flux:select>
                                            <div wire:key="action-{{ $index }}-field-{{ $actionField?->id }}">
                                                @switch($actionField?->type)
                                                    @case(null)
                                                        <flux:input disabled :placeholder="__('Empty (clears the field)')" aria-label="{{ __('Value') }}" />
                                                        @break
                                                    @case(\App\Enums\CustomFieldType::Select)
                                                        <flux:select variant="listbox" wire:model="actions.{{ $index }}.value" :placeholder="__('Empty (clears the field)')" clearable aria-label="{{ __('Value') }}">
                                                            @foreach ($actionField->options as $option)
                                                                <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                                                            @endforeach
                                                        </flux:select>
                                                        @break
                                                    @case(\App\Enums\CustomFieldType::Number)
                                                        <flux:input wire:model="actions.{{ $index }}.value" type="number" step="any" :placeholder="__('Empty (clears the field)')" aria-label="{{ __('Value') }}" />
                                                        @break
                                                    @case(\App\Enums\CustomFieldType::Date)
                                                        <flux:date-picker wire:model="actions.{{ $index }}.value" locale="{{ app()->getLocale() }}" :placeholder="__('Empty (clears the field)')" clearable aria-label="{{ __('Value') }}" />
                                                        @break
                                                    @default
                                                        <flux:input wire:model="actions.{{ $index }}.value" :placeholder="__('Empty (clears the field)')" aria-label="{{ __('Value') }}" />
                                                @endswitch
                                            </div>
                                        </div>
                                        @break
                                    @case('shift_due_date')
                                        <flux:input type="number" min="-365" max="365" wire:model="actions.{{ $index }}.value" placeholder="{{ __('Days, e.g. 7 or -2') }}" aria-label="{{ __('Days') }}" />
                                        @break
                                    @default
                                        <flux:textarea rows="2" wire:model="actions.{{ $index }}.value" placeholder="{{ __('Text of the comment') }}" aria-label="{{ __('Comment') }}" />
                                @endswitch
                            </div>

                            @if (count($actions) > 1)
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeAction({{ $index }})" aria-label="{{ __('Remove action') }}" />
                            @endif
                        </div>
                        <flux:error :name="'actions.'.$index.'.type'" />
                        <flux:error :name="'actions.'.$index.'.field'" />
                        <flux:error :name="'actions.'.$index.'.value'" />
                    </div>
                @endforeach
                <flux:error name="actions" />

                @if (count($actions) < 8)
                    <flux:button size="sm" icon="plus" wire:click="addAction">{{ __('Add action') }}</flux:button>
                @endif
            </div>

            <flux:text size="sm" class="text-zinc-500">{{ __('The rule acts with your rights. If you may no longer edit this project, it switches itself off.') }}</flux:text>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-automation" class="min-w-[22rem]">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Delete automation?') }}</flux:heading>
            <flux:text>{{ __('The rule stops working. Entries it already made in the history stay.') }}</flux:text>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
