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
        $this->refresh();
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
        $this->refresh();
    }

    public function updatedColors(string $value, string $id): void
    {
        $tag = $this->tagOrFail($id);

        if (! in_array($value, Tag::COLORS, true)) {
            $this->colors[$id] = $tag->color;

            return;
        }

        $tag->update(['color' => $value]);
        $this->refresh();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = (string) $this->tagOrFail($id)->id;
        $this->mergeIntoId = '';
        Flux::modal('delete-tag')->show();
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
        Flux::modal('delete-tag')->close();
        $this->refresh();
    }

    public function rendering($view): void
    {
        $view->title('Tags – '.$this->project->name);
    }
};
?>

<div class="max-w-2xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>Projekte</flux:breadcrumbs.item>
        <flux:breadcrumbs.item href="{{ route('projects.show', $project) }}" wire:navigate>{{ $project->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Tags</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl" class="mb-1">Tags</flux:heading>
    <flux:text class="mb-6">Tags gelten nur in diesem Projekt. Umbenennen und Umfärben wirkt sofort auf alle Aufgaben, beim Löschen lassen sich die Aufgaben auf ein anderes Tag übertragen.</flux:text>

    @if ($this->tags->isEmpty())
        <flux:callout icon="tag" heading="Noch keine Tags" text="Lege hier das erste Tag an oder direkt an einer Aufgabe." />
    @else
        <ul class="space-y-2">
            @foreach ($this->tags as $tag)
                <li wire:key="tag-{{ $tag->id }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:badge size="sm" :color="$tag->color" class="shrink-0" title="Aufgaben mit diesem Tag">{{ $tag->tasks_count }}</flux:badge>
                    <flux:input size="sm" wire:model.blur="names.{{ $tag->id }}" aria-label="Name" class="min-w-0 flex-1" />
                    <flux:select size="sm" variant="listbox" wire:model.live="colors.{{ $tag->id }}" aria-label="Farbe" class="max-w-28">
                        @foreach (\App\Models\Tag::COLORS as $color)
                            <flux:select.option value="{{ $color }}">{{ $color }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $tag->id }})" aria-label="Tag löschen" />
                </li>
            @endforeach
        </ul>
    @endif

    <form wire:submit="add" class="mt-6 flex items-end gap-2">
        <flux:input wire:model="newName" label="Neues Tag" placeholder="z. B. Dringend" class="max-w-xs" />
        <flux:button type="submit" icon="plus">Anlegen</flux:button>
    </form>
    @error('newName') <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror

    <flux:modal name="delete-tag" class="min-w-[24rem]">
        <form wire:submit="delete" class="space-y-6">
            <flux:heading size="lg">Tag löschen?</flux:heading>
            <flux:text>Das Tag wird von allen Aufgaben entfernt. Wahlweise bekommen diese Aufgaben stattdessen ein anderes Tag.</flux:text>
            <flux:select variant="listbox" wire:model="mergeIntoId" label="Stattdessen zuweisen (optional)" placeholder="Kein Ersatz">
                <flux:select.option value="">Kein Ersatz</flux:select.option>
                @foreach ($this->tags->where('id', '!=', (int) $deletingId) as $candidate)
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
