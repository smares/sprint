<?php

use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public Project $project;

    public string $name = '';

    public string $description = '';

    public string $color = '';

    public string $icon = '';

    public string $confirmName = '';

    public function hydrate(): void
    {
        Gate::authorize('manage', $this->project);
    }

    public function mount(): void
    {
        Gate::authorize('manage', $this->project);

        $this->name = $this->project->name;
        $this->description = (string) $this->project->description;
        $this->color = (string) $this->project->color;
        $this->icon = (string) $this->project->icon;
    }

    /**
     * Emojis offered with one click for the project's icon; any other single emoji or character can be typed.
     *
     * @return list<string>
     */
    public function suggestedIcons(): array
    {
        return ['🚀', '💡', '🎯', '📈', '🛠️', '🎨', '📱', '🌐', '📣', '🧪', '📦', '🏠', '💼', '🤝', '📚', '⭐'];
    }

    public function save(): void
    {
        Gate::authorize('manage', $this->project);

        $this->icon = trim($this->icon);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // One emoji or character (an emoji may consist of several code points, so it is counted as the eye sees it)
            'icon' => ['nullable', 'string', 'max:32', function (string $attribute, string $value, \Closure $fail) {
                if (grapheme_strlen($value) !== 1) {
                    $fail(__('Choose a single emoji or character.'));
                }
            }],
        ], attributes: ['name' => __('Name'), 'description' => __('Description'), 'color' => __('Color'), 'icon' => __('Icon')]);

        $this->project->update([
            'name' => trim($validated['name']),
            'description' => $validated['description'] ?: null,
            'color' => $validated['color'] ? strtolower($validated['color']) : null,
            'icon' => $validated['icon'] ?: null,
        ]);

        Flux::toast(variant: 'success', text: __('Project saved.'));
        $this->dispatch('project-updated');
    }

    public function archive(): void
    {
        Gate::authorize('manage', $this->project);

        $this->project->update(['archived_at' => now()]);

        $this->dispatch('project-updated');
        Flux::toast(text: __('Project archived. It is now read-only.'));
    }

    public function restore(): void
    {
        Gate::authorize('manage', $this->project);

        $this->project->update(['archived_at' => null]);

        $this->dispatch('project-updated');
        Flux::toast(variant: 'success', text: __('Project restored.'));
    }

    public function delete(): void
    {
        Gate::authorize('manage', $this->project);

        $this->validate(['confirmName' => ['required', Rule::in([$this->project->name])]], [
            'confirmName.in' => __('The name does not match.'),
            'confirmName.required' => __('Type the project name to confirm.'),
        ], ['confirmName' => __('Confirmation')]);

        $this->project->delete();

        $this->redirectRoute('projects.index', navigate: true);
    }
};
?>

<div>
    <flux:modal name="project-settings" class="w-full max-w-lg" x-on:close="$wire.set('confirmName', '')">
        <div class="space-y-8">
            <flux:heading size="lg">{{ __('Project settings') }}</flux:heading>

            <form wire:submit="save" class="space-y-4">
                <flux:input wire:model="name" :label="__('Name')" />
                <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

                {{-- Color and icon of the project's tile; left empty, the project keeps a color of its own and its first letter --}}
                <div class="flex flex-col gap-4 sm:flex-row">
                    <flux:field class="sm:w-40">
                        <flux:label>{{ __('Color') }}</flux:label>
                        <div class="flex items-center gap-1">
                            <flux:color-picker type="button" size="sm" class="w-full" :swatches="\App\Color::swatches()" wire:model.live="color" :placeholder="__('Automatic')" :aria-label="__('Color')" />
                            @if ($color !== '')
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('color', '')" :aria-label="__('Automatic color')" :tooltip="__('Automatic color')" />
                            @endif
                        </div>
                        <flux:error name="color" />
                    </flux:field>
                    <flux:field class="flex-1">
                        <flux:label>{{ __('Icon') }}</flux:label>
                        <div class="flex items-center gap-2">
                            <div class="w-14 shrink-0">
                                <flux:input wire:model.live.debounce.300ms="icon" maxlength="32" class="[&_input]:text-center" :aria-label="__('Icon')" :placeholder="mb_strtoupper(mb_substr($name, 0, 1))" />
                            </div>
                            <div class="flex flex-wrap gap-0.5" role="group" aria-label="{{ __('Suggested icons') }}">
                                @foreach ($this->suggestedIcons() as $emoji)
                                    <button type="button" wire:key="icon-{{ $loop->index }}" wire:click="$set('icon', '{{ $emoji }}')" @class(['flex size-7 items-center justify-center rounded-md text-base hover:bg-zinc-100 dark:hover:bg-zinc-700', 'bg-zinc-100 ring-1 ring-zinc-300 dark:bg-zinc-700 dark:ring-zinc-500' => $icon === $emoji]) aria-label="{{ $emoji }}">{{ $emoji }}</button>
                                @endforeach
                            </div>
                        </div>
                        <flux:error name="icon" />
                    </flux:field>
                </div>
                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>

            <flux:separator />

            <div class="space-y-3">
                @if ($project->archived_at)
                    <flux:heading>{{ __('Archived') }}</flux:heading>
                    <flux:text size="sm">{{ __('The project is archived: it no longer appears in the project list and is read-only.') }}</flux:text>
                    <flux:button icon="arrow-uturn-left" wire:click="restore">{{ __('Restore') }}</flux:button>
                @else
                    <flux:heading>{{ __('Archive') }}</flux:heading>
                    <flux:text size="sm">{{ __('Archived projects disappear from the project list and are read-only. You can restore them at any time.') }}</flux:text>
                    <flux:button icon="archive-box" wire:click="archive" wire:confirm="{{ __('Archive project “:name”?', ['name' => $project->name]) }}">{{ __('Archive') }}</flux:button>
                @endif
            </div>

            <flux:separator />

            <form wire:submit="delete" class="space-y-3">
                <flux:heading class="text-red-600 dark:text-red-400">{{ __('Delete project') }}</flux:heading>
                <flux:text size="sm">{!! __('This permanently deletes the project with all tasks, comments and attachments. Type the name :name to confirm.', ['name' => '<strong>'.e($project->name).'</strong>']) !!}</flux:text>
                <flux:input wire:model="confirmName" placeholder="{{ $project->name }}" aria-label="{{ __('Project name to confirm') }}" />
                <flux:button type="submit" variant="danger" icon="trash">{{ __('Delete permanently') }}</flux:button>
            </form>
        </div>
    </flux:modal>
</div>
