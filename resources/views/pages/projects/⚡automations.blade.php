<?php

use App\Enums\AutomationAction;
use App\Enums\AutomationTrigger;
use App\Models\Automation;
use App\Models\Project;
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

    public string $conditionStatus = '';

    public string $conditionAssignee = '';

    public string $conditionTag = '';

    /** @var list<array{type: string, value: string}> */
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
     * @return array<int, string>
     */
    #[Computed]
    public function userNames(): array
    {
        return $this->project->eligibleUsers()->orderBy('name')->pluck('name', 'users.id')->all();
    }

    public function openForm(?int $id = null): void
    {
        $this->resetErrorBag();
        $rule = $id === null ? null : $this->ruleOrFail($id);

        $this->editingId = $rule?->id;
        $this->name = $rule->name ?? '';
        $this->trigger = ($rule->trigger ?? AutomationTrigger::StatusChanged)->value;
        $this->triggerValue = (string) ($rule?->trigger_value ?? '');
        $this->conditionStatus = (string) ($rule?->conditions['status_id'] ?? '');
        $this->conditionAssignee = (string) ($rule?->conditions['assignee_id'] ?? '');
        $this->conditionTag = (string) ($rule?->conditions['tag_id'] ?? '');
        $this->actions = $rule === null
            ? [['type' => '', 'value' => '']]
            : array_map(fn (array $step) => ['type' => $step['type']->value, 'value' => (string) ($step['value'] ?? '')], $rule->steps());

        Flux::modal('automation-form')->show();
    }

    public function updatedTrigger(): void
    {
        $this->triggerValue = '';
    }

    public function updatedActions(mixed $value, ?string $key = null): void
    {
        if ($key !== null && str_ends_with($key, '.type')) {
            $this->actions[(int) $key]['value'] = '';
        }
    }

    public function addAction(): void
    {
        if (count($this->actions) < self::MAX_ACTIONS) {
            $this->actions[] = ['type' => '', 'value' => ''];
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
        $triggerValue = $this->idOf('triggerValue', array_keys($trigger === AutomationTrigger::StatusChanged ? $this->statusNames : ($trigger === AutomationTrigger::TagAdded ? $this->tagNames : $this->userNames)), $trigger->valueIsOptional());
        $conditions = array_filter([
            'status_id' => $this->idOf('conditionStatus', array_keys($this->statusNames), true),
            'assignee_id' => $this->idOf('conditionAssignee', array_keys($this->userNames), true),
            'tag_id' => $this->idOf('conditionTag', array_keys($this->tagNames), true),
        ]);

        $steps = [];

        foreach ($this->actions as $index => $action) {
            $steps[] = ['type' => $action['type'], 'value' => $this->actionValue($index, AutomationAction::from($action['type']), trim($action['value']))];
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $attributes = [
            'name' => trim($validated['name']),
            'trigger' => $trigger,
            'trigger_value' => $triggerValue,
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

    private function actionValue(int $index, AutomationAction $action, string $value): int|string|null
    {
        $key = "actions.$index.value";

        return match ($action) {
            AutomationAction::SetAssignee => $value === '' ? null : $this->idOf($key, array_keys($this->userNames), false),
            AutomationAction::Notify => $this->idOf($key, array_keys($this->userNames), false),
            AutomationAction::SetStatus => $this->idOf($key, array_keys($this->statusNames), false),
            AutomationAction::AddTag => $this->idOf($key, array_keys($this->tagNames), false),
            AutomationAction::ShiftDueDate => $this->days($key, $value),
            AutomationAction::Comment => $this->text($key, $value),
        };
    }

    private function days(string $key, string $value): ?int
    {
        if (! preg_match('/^-?\d{1,3}$/', $value) || (int) $value === 0) {
            $this->addError($key, __('Enter a number of days between -365 and 365, not 0.'));

            return null;
        }

        if (abs((int) $value) > 365) {
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
        }, $rule->steps());
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

    <div class="mb-6 flex items-start gap-4">
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
                        <flux:switch wire:click="toggle({{ $rule->id }})" :checked="$rule->enabled" aria-label="{{ __('Turn on or off') }}" />
                        <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openForm({{ $rule->id }})" aria-label="{{ __('Edit automation') }}" />
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $rule->id }})" aria-label="{{ __('Delete automation') }}" />
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
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:select variant="listbox" wire:model.live="trigger" aria-label="{{ __('When') }}">
                        @foreach (\App\Enums\AutomationTrigger::cases() as $case)
                            <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select variant="listbox" wire:model="triggerValue" :placeholder="$trigger === 'assignee_changed' ? __('Anyone') : __('Choose …')" clearable aria-label="{{ __('Value') }}">
                        @foreach (match ($trigger) { 'status_changed' => $this->statusNames, 'tag_added' => $this->tagNames, default => $this->userNames } as $id => $label)
                            <flux:select.option value="{{ $id }}" wire:key="trigger-{{ $trigger }}-{{ $id }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:error name="triggerValue" />
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
