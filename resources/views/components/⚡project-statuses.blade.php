<?php

use App\Models\Project;
use App\Models\TaskStatus;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
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
    public function statuses()
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
            'position' => ($this->project->statuses()->max('position') ?? -1) + 1,
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

            return;
        }

        $status->update(['name' => $name]);
        $this->changed();
    }

    public function updatedColors(string $value, string $id): void
    {
        $status = $this->statusOrFail($id);

        if (! in_array($value, TaskStatus::COLORS, true)) {
            $this->colors[$id] = $status->color;

            return;
        }

        $status->update(['color' => $value]);
        $this->changed();
    }

    public function updatedDone(bool $value, string $id): void
    {
        $status = $this->statusOrFail($id);

        if (! $this->keepsBothKinds($status->id, $value)) {
            $this->done[$id] = $status->is_done;
            Flux::toast(variant: 'danger', text: 'Es braucht mindestens einen offenen und einen erledigten Status.');

            return;
        }

        $status->update(['is_done' => $value]);
        $this->changed();
    }

    public function move(int|string $id, int $position): void
    {
        $status = $this->statusOrFail($id);
        $orderedIds = $this->project->statuses()->whereKeyNot($status->id)->pluck('id')->all();

        array_splice($orderedIds, max(0, min($position, count($orderedIds))), 0, [$status->id]);

        foreach ($orderedIds as $index => $statusId) {
            TaskStatus::whereKey($statusId)->update(['position' => $index]);
        }

        $this->changed();
    }

    public function confirmDelete(int $id): void
    {
        $status = $this->statusOrFail($id);

        if (! $this->keepsBothKinds($status->id, null)) {
            Flux::toast(variant: 'danger', text: 'Es braucht mindestens einen offenen und einen erledigten Status.');

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
        ], attributes: ['replacementId' => 'Ersatz-Status']);

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
    <flux:modal.trigger name="project-statuses">
        <flux:button icon="cog-6-tooth">Status</flux:button>
    </flux:modal.trigger>

    <flux:modal name="project-statuses" class="w-full max-w-2xl" x-on:close="$wire.closed()">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Status</flux:heading>
                <flux:text class="mt-1">Die Reihenfolge bestimmt die Spalten im Board. Neue Aufgaben starten im ersten Status, der nicht als erledigt gilt.</flux:text>
            </div>

            <ul class="max-h-96 space-y-2 overflow-y-auto" wire:sort="move">
                @foreach ($this->statuses as $status)
                    <li wire:key="status-{{ $status->id }}" wire:sort:item="{{ $status->id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="flex items-center gap-3">
                            <flux:icon.bars-2 variant="micro" class="shrink-0 text-zinc-400" />
                            <flux:badge size="sm" :color="$status->color" class="shrink-0" title="Aufgaben mit diesem Status">{{ $status->tasks_count }}</flux:badge>
                            <flux:input size="sm" wire:model.blur="names.{{ $status->id }}" aria-label="Name" class="min-w-0 flex-1" />
                            <flux:select size="sm" variant="listbox" wire:model.live="colors.{{ $status->id }}" aria-label="Farbe" class="max-w-28">
                                @foreach (\App\Models\TaskStatus::COLORS as $color)
                                    <flux:select.option value="{{ $color }}">{{ $color }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:checkbox wire:model.live="done.{{ $status->id }}" label="Erledigt" />
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $status->id }})" aria-label="Status löschen" />
                        </div>

                        @if ($deletingId === (string) $status->id)
                            <form wire:submit="delete" class="mt-3 space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                @if ($status->tasks_count > 0)
                                    <flux:text size="sm">Die {{ $status->tasks_count }} Aufgaben mit diesem Status wechseln in den Ersatz-Status.</flux:text>
                                    <flux:select size="sm" variant="listbox" wire:model="replacementId" label="Ersatz-Status" placeholder="Status wählen …">
                                        @foreach ($this->statuses->where('id', '!=', $status->id) as $candidate)
                                            <flux:select.option value="{{ $candidate->id }}">{{ $candidate->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @error('replacementId') <flux:text size="sm" class="text-red-500">{{ $message }}</flux:text> @enderror
                                @else
                                    <flux:text size="sm">Der Status wird gelöscht.</flux:text>
                                @endif
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" wire:click="cancelDelete">Abbrechen</flux:button>
                                    <flux:button size="sm" type="submit" variant="danger">Löschen</flux:button>
                                </div>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>

            <form wire:submit="add" class="flex items-end gap-2">
                <flux:input wire:model="newName" label="Neuer Status" placeholder="z. B. Im Test" class="flex-1" />
                <flux:button type="submit" icon="plus">Anlegen</flux:button>
            </form>
            @error('newName') <flux:text class="-mt-4 text-red-500">{{ $message }}</flux:text> @enderror
        </div>
    </flux:modal>
</div>
