<?php

use App\Color;
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
            $this->addError('newName', __('This tag already exists in this project.'));

            return;
        }

        $this->project->tags()->create([
            'name' => $name,
            'color' => Color::next($this->project->tags()->count()),
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
            Flux::toast(variant: 'danger', text: __('The tag “:name” already exists in this project.', ['name' => $name]));

            return;
        }

        $tag->update(['name' => $name]);
        $this->changed();
    }

    public function updatedColors(string $value, string $id): void
    {
        $tag = $this->tagOrFail($id);

        if (! Color::isHex($value)) {
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
        ], attributes: ['mergeIntoId' => __('Replacement tag')]);

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
    <flux:modal name="project-tags" class="w-full max-w-xl" x-on:close="$wire.closed()">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Tags') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Tags only apply to this project. Renaming and recoloring takes effect on all tasks immediately.') }}</flux:text>
            </div>

            @if ($this->tags->isEmpty())
                <flux:callout icon="tag" :heading="__('No tags yet')" :text="__('Create the first tag here or directly on a task.')" />
            @else
                <ul class="max-h-96 space-y-2 overflow-y-auto">
                    @foreach ($this->tags as $tag)
                        <li wire:key="tag-{{ $tag->id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="flex items-center gap-3">
                                <x-color-badge size="sm" :color="$tag->color" class="shrink-0" title="{{ __('Tasks with this tag') }}">{{ $tag->tasks_count }}</x-color-badge>
                                <flux:input size="sm" wire:model.blur="names.{{ $tag->id }}" aria-label="{{ __('Name') }}" class="min-w-0 flex-1" />
                                <flux:color-picker type="button" size="sm" with-confirmation :swatches="\App\Color::SWATCHES" wire:model.live="colors.{{ $tag->id }}" aria-label="{{ __('Color') }}" />
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $tag->id }})" aria-label="{{ __('Delete tag') }}" />
                            </div>

                            @if ($deletingId === (string) $tag->id)
                                <form wire:submit="delete" class="mt-3 space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                    <flux:text size="sm">{{ __('The tag will be removed from all tasks. Optionally, those tasks get another tag instead.') }}</flux:text>
                                    <flux:select size="sm" variant="listbox" wire:model="mergeIntoId" :label="__('Assign instead (optional)')">
                                        <flux:select.option value="">{{ __('No replacement') }}</flux:select.option>
                                        @foreach ($this->tags->where('id', '!=', $tag->id) as $candidate)
                                            <flux:select.option value="{{ $candidate->id }}">{{ $candidate->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @error('mergeIntoId') <flux:text size="sm" class="text-red-500">{{ $message }}</flux:text> @enderror
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="sm" variant="ghost" wire:click="cancelDelete">{{ __('Cancel') }}</flux:button>
                                        <flux:button size="sm" type="submit" variant="danger">{{ __('Delete') }}</flux:button>
                                    </div>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <form wire:submit="add" class="flex items-end gap-2">
                <flux:input wire:model="newName" :label="__('New tag')" :placeholder="__('e.g. Important')" class="flex-1" />
                <flux:button type="submit" icon="plus">{{ __('Create') }}</flux:button>
            </form>
            @error('newName') <flux:text class="-mt-4 text-red-500">{{ $message }}</flux:text> @enderror
        </div>
    </flux:modal>
</div>
