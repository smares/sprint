<?php

use App\Color;
use App\Concerns\ConfirmsAutosave;
use App\Models\Project;
use App\Models\TaskStatus;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use ConfirmsAutosave;

    public Project $project;

    public string $newName = '';

    /** @var array<int|string, string> */
    public array $names = [];

    /** @var array<int|string, string> */
    public array $colors = [];

    /** @var array<int|string, bool> */
    public array $done = [];

    public bool $dirty = false;

    public string $deletingId = '';

    public string $replacementId = '';

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
    public function statuses(): Collection
    {
        return $this->project->statuses()->withCount('tasks')->get();
    }

    private function fillForm(): void
    {
        $this->names = $this->statuses->pluck('name', 'id')->all();
        $this->colors = $this->statuses->pluck('color', 'id')->all();
        $this->done = $this->statuses->pluck('is_done', 'id')->all();
    }

    private function statusOrFail(int|string $id): TaskStatus
    {
        return $this->project->statuses()->findOrFail($id);
    }

    private function refresh(): void
    {
        unset($this->statuses);
        $this->fillForm();
    }

    private function changed(): void
    {
        $this->refresh();
        $this->dirty = true;
    }

    public function cancelDelete(): void
    {
        $this->reset('deletingId', 'replacementId');
    }

    /**
     * The page behind the modal is only refreshed once the modal is closed, so it does not re-render under it.
     */
    public function closed(): void
    {
        $this->cancelDelete();

        if ($this->dirty) {
            $this->dirty = false;
            $this->dispatch('statuses-changed');
        }
    }

    /**
     * A project needs at least one open and one done status.
     */
    private function keepsBothKinds(int $changedId, ?bool $becomesDone): bool
    {
        $others = $this->project->statuses()->whereKeyNot($changedId)->get();
        $hasOpen = $others->contains(fn (TaskStatus $status) => ! $status->is_done) || $becomesDone === false;
        $hasDone = $others->contains(fn (TaskStatus $status) => $status->is_done) || $becomesDone === true;

        return $hasOpen && $hasDone;
    }

    public function add(): void
    {
        $validated = $this->validate(['newName' => ['required', 'string', 'max:50']]);

        $this->project->statuses()->create([
            'name' => trim($validated['newName']),
            'position' => TaskStatus::nextPositionIn($this->project->statuses()),
        ]);

        $this->reset('newName');
        $this->changed();
    }

    public function updatedNames(string $value, string $id): void
    {
        $status = $this->statusOrFail($id);
        $name = trim($value);

        if ($name === '' || mb_strlen($name) > 50) {
            $this->names[$id] = $status->name;
            Flux::toast(variant: 'danger', text: __('The name must not be empty and may have at most :max characters.', ['max' => 50]));

            return;
        }

        $status->update(['name' => $name]);
        $this->changed();
        $this->confirmSaved();
    }

    public function updatedColors(string $value, string $id): void
    {
        $status = $this->statusOrFail($id);

        if (! Color::isHex($value)) {
            $this->colors[$id] = $status->color;

            return;
        }

        $status->update(['color' => $value]);
        $this->changed();
        $this->confirmSaved();
    }

    public function updatedDone(bool $value, string $id): void
    {
        $status = $this->statusOrFail($id);

        if (! $this->keepsBothKinds($status->id, $value)) {
            $this->done[$id] = $status->is_done;
            Flux::toast(variant: 'danger', text: __('At least one open and one done status are required.'));

            return;
        }

        $status->update(['is_done' => $value]);
        $this->changed();
        $this->confirmSaved();
    }

    public function move(int|string $id, int $position): void
    {
        $this->statusOrFail($id)->moveTo($position);

        $this->changed();
    }

    public function confirmDelete(int $id): void
    {
        $status = $this->statusOrFail($id);

        if (! $this->keepsBothKinds($status->id, null)) {
            Flux::toast(variant: 'danger', text: __('At least one open and one done status are required.'));

            return;
        }

        $this->deletingId = (string) $status->id;
        $this->replacementId = '';
    }

    public function delete(): void
    {
        $status = $this->statusOrFail($this->deletingId);

        abort_unless($this->keepsBothKinds($status->id, null), 422);

        $needsReplacement = $status->tasks()->exists();

        $this->validate([
            'replacementId' => [
                Rule::requiredIf($needsReplacement),
                'nullable',
                Rule::exists('task_statuses', 'id')->where('project_id', $this->project->id)->whereNot('id', $status->id),
            ],
        ], attributes: ['replacementId' => __('Replacement status')]);

        if ($needsReplacement) {
            $status->tasks()->update(['status_id' => $this->replacementId]);
        }

        $status->delete();

        $this->reset('deletingId', 'replacementId');
        $this->changed();
    }

};
?>

<div>
    <flux:modal name="project-statuses" class="w-full max-w-2xl" x-on:close="$wire.closed()">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Status') }}</flux:heading>
                <flux:text class="mt-1">{{ __('The order determines the columns on the board. New tasks start in the first status that does not count as done.') }}</flux:text>
            </div>

            <ul class="max-h-96 space-y-2 overflow-y-auto" wire:sort="move">
                @foreach ($this->statuses as $status)
                    <li wire:key="status-{{ $status->id }}" wire:sort:item="{{ $status->id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        {{-- On phones colour, "done" and delete move to a second line instead of squeezing the name --}}
                        <div class="flex flex-wrap items-center gap-3">
                            <flux:icon.bars-2 variant="micro" class="shrink-0 text-zinc-400" />
                            <x-color-badge size="sm" :color="$status->color" class="shrink-0" title="{{ __('Tasks with this status') }}">{{ $status->tasks_count }}</x-color-badge>
                            <flux:input size="sm" wire:model.live.blur="names.{{ $status->id }}" aria-label="{{ __('Name') }}" class="min-w-36 flex-1" />
                            <flux:color-picker type="button" size="sm" class="w-32 shrink-0" with-confirmation :swatches="\App\Color::swatches()" wire:model.live="colors.{{ $status->id }}" aria-label="{{ __('Color') }}" />
                            <flux:checkbox wire:model.live="done.{{ $status->id }}" :label="__('Done')" />
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $status->id }})" aria-label="{{ __('Delete status') }}" />
                        </div>

                        @if ($deletingId === (string) $status->id)
                            <form wire:submit="delete" class="mt-3 space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                @if ($status->tasks_count > 0)
                                    <flux:text size="sm">{{ __('The :count tasks with this status move to the replacement status.', ['count' => $status->tasks_count]) }}</flux:text>
                                    <flux:select size="sm" variant="listbox" wire:model="replacementId" :label="__('Replacement status')" :placeholder="__('Select status…')">
                                        @foreach ($this->statuses->where('id', '!=', $status->id) as $candidate)
                                            <flux:select.option value="{{ $candidate->id }}">{{ $candidate->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="replacementId" />
                                @else
                                    <flux:text size="sm">{{ __('The status will be deleted.') }}</flux:text>
                                @endif
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" wire:click="cancelDelete">{{ __('Cancel') }}</flux:button>
                                    <flux:button size="sm" type="submit" variant="danger">{{ __('Delete') }}</flux:button>
                                </div>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>

            <form wire:submit="add" class="flex items-end gap-2">
                <flux:input wire:model="newName" :label="__('New status')" :placeholder="__('e.g. In testing')" class="flex-1" />
                <flux:button type="submit" icon="plus">{{ __('Create') }}</flux:button>
            </form>
            <flux:error name="newName" class="-mt-4" />
        </div>
    </flux:modal>
</div>
