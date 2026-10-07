<?php

use App\Models\Project;
use App\Models\Tag;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
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

    public bool $dirty = false;

    public string $deletingId = '';

    public string $mergeIntoId = '';

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
    public function tags()
    {
        return $this->project->tags()->withCount('tasks')->orderBy('name')->get();
    }

    private function fillForm(): void
    {
        $this->names = $this->tags->pluck('name', 'id')->all();
        $this->colors = $this->tags->pluck('color', 'id')->all();
    }

    private function tagOrFail(int|string $id): Tag
    {
        return $this->project->tags()->findOrFail($id);
    }

    private function refresh(): void
    {
        unset($this->tags);
        $this->fillForm();
    }

    private function changed(): void
    {
        $this->refresh();
        $this->dirty = true;
    }

    public function cancelDelete(): void
    {
        $this->reset('deletingId', 'mergeIntoId');
    }

    /**
     * The page behind the modal is only refreshed once the modal is closed, so it does not re-render under it.
     */
    public function closed(): void
    {
        $this->cancelDelete();

        if ($this->dirty) {
            $this->dirty = false;
            $this->dispatch('tags-changed');
        }
    }

    private function nameTaken(string $name, ?int $exceptId = null): bool
    {
        return $this->project->tags()
            ->when($exceptId, fn ($tags) => $tags->whereKeyNot($exceptId))
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->exists();
    }

    public function add(): void
    {
        $validated = $this->validate(['newName' => ['required', 'string', 'max:50']]);
        $name = trim($validated['newName']);

        if ($this->nameTaken($name)) {
            $this->addError('newName', 'Dieses Tag gibt es in diesem Projekt schon.');

            return;
        }

        $this->project->tags()->create([
            'name' => $name,
            'color' => Tag::COLORS[$this->project->tags()->count() % count(Tag::COLORS)],
        ]);

        $this->reset('newName');
        $this->changed();
    }

    public function updatedNames(string $value, string $id): void
    {
        $tag = $this->tagOrFail($id);
        $name = trim($value);

        if ($name === '' || mb_strlen($name) > 50) {
            $this->names[$id] = $tag->name;

            return;
        }

        if ($this->nameTaken($name, $tag->id)) {
            $this->names[$id] = $tag->name;
            Flux::toast(variant: 'danger', text: "Das Tag „{$name}“ gibt es in diesem Projekt schon.");

            return;
        }

        $tag->update(['name' => $name]);
        $this->changed();
    }

    public function updatedColors(string $value, string $id): void
    {
        $tag = $this->tagOrFail($id);

        if (! in_array($value, Tag::COLORS, true)) {
            $this->colors[$id] = $tag->color;

            return;
        }

        $tag->update(['color' => $value]);
        $this->changed();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = (string) $this->tagOrFail($id)->id;
        $this->mergeIntoId = '';
    }

    public function delete(): void
    {
        $tag = $this->tagOrFail($this->deletingId);

        $this->validate([
            'mergeIntoId' => [
                'nullable',
                Rule::exists('tags', 'id')->where('project_id', $this->project->id)->whereNot('id', $tag->id),
            ],
        ], attributes: ['mergeIntoId' => 'Ersatz-Tag']);

        DB::transaction(function () use ($tag) {
            if ($this->mergeIntoId !== '') {
                $target = $this->tagOrFail($this->mergeIntoId);
                $target->tasks()->syncWithoutDetaching($tag->tasks()->pluck('tasks.id')->all());
            }

            $tag->delete();
        });

        $this->reset('deletingId', 'mergeIntoId');
        $this->changed();
    }
};
?>

<div>
    <flux:modal.trigger name="project-tags">
        <flux:button icon="tag">Tags</flux:button>
    </flux:modal.trigger>

    <flux:modal name="project-tags" class="w-full max-w-xl" x-on:close="$wire.closed()">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Tags</flux:heading>
                <flux:text class="mt-1">Tags gelten nur in diesem Projekt. Umbenennen und Umfärben wirkt sofort auf alle Aufgaben.</flux:text>
            </div>

            @if ($this->tags->isEmpty())
                <flux:callout icon="tag" heading="Noch keine Tags" text="Lege hier das erste Tag an oder direkt an einer Aufgabe." />
            @else
                <ul class="max-h-96 space-y-2 overflow-y-auto">
                    @foreach ($this->tags as $tag)
                        <li wire:key="tag-{{ $tag->id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="flex items-center gap-3">
                                <flux:badge size="sm" :color="$tag->color" class="shrink-0" title="Aufgaben mit diesem Tag">{{ $tag->tasks_count }}</flux:badge>
                                <flux:input size="sm" wire:model.blur="names.{{ $tag->id }}" aria-label="Name" class="min-w-0 flex-1" />
                                <flux:select size="sm" variant="listbox" wire:model.live="colors.{{ $tag->id }}" aria-label="Farbe" class="max-w-28">
                                    @foreach (\App\Models\Tag::COLORS as $color)
                                        <flux:select.option value="{{ $color }}">{{ $color }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $tag->id }})" aria-label="Tag löschen" />
                            </div>

                            @if ($deletingId === (string) $tag->id)
                                <form wire:submit="delete" class="mt-3 space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                    <flux:text size="sm">Das Tag wird von allen Aufgaben entfernt. Wahlweise bekommen diese Aufgaben stattdessen ein anderes Tag.</flux:text>
                                    <flux:select size="sm" variant="listbox" wire:model="mergeIntoId" label="Stattdessen zuweisen (optional)">
                                        <flux:select.option value="">Kein Ersatz</flux:select.option>
                                        @foreach ($this->tags->where('id', '!=', $tag->id) as $candidate)
                                            <flux:select.option value="{{ $candidate->id }}">{{ $candidate->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @error('mergeIntoId') <flux:text size="sm" class="text-red-500">{{ $message }}</flux:text> @enderror
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="sm" variant="ghost" wire:click="cancelDelete">Abbrechen</flux:button>
                                        <flux:button size="sm" type="submit" variant="danger">Löschen</flux:button>
                                    </div>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <form wire:submit="add" class="flex items-end gap-2">
                <flux:input wire:model="newName" label="Neues Tag" placeholder="z. B. Dringend" class="flex-1" />
                <flux:button type="submit" icon="plus">Anlegen</flux:button>
            </form>
            @error('newName') <flux:text class="-mt-4 text-red-500">{{ $message }}</flux:text> @enderror
        </div>
    </flux:modal>
</div>
