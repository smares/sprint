<?php

use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Project $project;

    public string $name = '';

    public string $description = '';

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
    }

    public function save(): void
    {
        Gate::authorize('manage', $this->project);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ], attributes: ['name' => __('Name'), 'description' => __('Description')]);

        $this->project->update(['name' => trim($validated['name']), 'description' => $validated['description'] ?: null]);

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

        $this->validate(['confirmName' => ['required', 'in:'.$this->project->name]], [
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
