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
        $this->refresh();
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
        $this->refresh();
    }

    public function updatedColors(string $value, string $id): void
    {
        $status = $this->statusOrFail($id);

        if (! in_array($value, TaskStatus::COLORS, true)) {
            $this->colors[$id] = $status->color;

            return;
        }

        $status->update(['color' => $value]);
        $this->refresh();
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
        $this->refresh();
    }

    public function move(int|string $id, int $position): void
    {
        $status = $this->statusOrFail($id);
        $orderedIds = $this->project->statuses()->whereKeyNot($status->id)->pluck('id')->all();

        array_splice($orderedIds, max(0, min($position, count($orderedIds))), 0, [$status->id]);

        foreach ($orderedIds as $index => $statusId) {
            TaskStatus::whereKey($statusId)->update(['position' => $index]);
        }

        $this->refresh();
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
        Flux::modal('delete-status')->show();
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
        Flux::modal('delete-status')->close();
        $this->refresh();
    }

    public function rendering($view): void
    {
        $view->title('Status – '.$this->project->name);
    }
};
?>

<div class="max-w-2xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>Projekte</flux:breadcrumbs.item>
        <flux:breadcrumbs.item href="{{ route('projects.show', $project) }}" wire:navigate>{{ $project->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Status</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl" class="mb-1">Status</flux:heading>
    <flux:text class="mb-6">Die Reihenfolge bestimmt die Spalten im Board. Neue Aufgaben starten im ersten Status, der nicht als erledigt gilt.</flux:text>

    <ul class="space-y-2" wire:sort="move">
        @foreach ($this->statuses as $status)
            <li wire:key="status-{{ $status->id }}" wire:sort:item="{{ $status->id }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <flux:icon.bars-2 variant="micro" class="shrink-0 text-zinc-400" />
                <flux:badge size="sm" :color="$status->color" class="shrink-0">{{ $status->tasks_count }}</flux:badge>
                <flux:input size="sm" wire:model.blur="names.{{ $status->id }}" aria-label="Name" class="min-w-0 flex-1" />
                <flux:select size="sm" variant="listbox" wire:model.live="colors.{{ $status->id }}" aria-label="Farbe" class="max-w-28">
                    @foreach (\App\Models\TaskStatus::COLORS as $color)
                        <flux:select.option value="{{ $color }}">{{ $color }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:checkbox wire:model.live="done.{{ $status->id }}" label="Erledigt" />
                <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $status->id }})" aria-label="Status löschen" />
            </li>
        @endforeach
    </ul>

    <form wire:submit="add" class="mt-6 flex items-end gap-2">
        <flux:input wire:model="newName" label="Neuer Status" placeholder="z. B. Im Test" class="max-w-xs" />
        <flux:button type="submit" icon="plus">Anlegen</flux:button>
    </form>
    @error('newName') <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror

    <flux:modal name="delete-status" class="min-w-[24rem]">
        <form wire:submit="delete" class="space-y-6">
            <flux:heading size="lg">Status löschen?</flux:heading>
            <flux:text>Aufgaben mit diesem Status wechseln in den Ersatz-Status.</flux:text>
            <flux:select variant="listbox" wire:model="replacementId" label="Ersatz-Status" placeholder="Status wählen …">
                @foreach ($this->statuses->where('id', '!=', (int) $deletingId) as $candidate)
                    <flux:select.option value="{{ $candidate->id }}">{{ $candidate->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Abbrechen</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">Löschen</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
