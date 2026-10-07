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
        ], attributes: ['name' => 'Name', 'description' => 'Beschreibung']);

        $this->project->update(['name' => trim($validated['name']), 'description' => $validated['description'] ?: null]);

        Flux::toast(variant: 'success', text: 'Projekt gespeichert.');
        $this->dispatch('project-updated');
    }

    public function archive(): void
    {
        Gate::authorize('manage', $this->project);

        $this->project->update(['archived_at' => now()]);

        $this->dispatch('project-updated');
        Flux::toast(text: 'Projekt archiviert. Es ist jetzt nur noch lesbar.');
    }

    public function restore(): void
    {
        Gate::authorize('manage', $this->project);

        $this->project->update(['archived_at' => null]);

        $this->dispatch('project-updated');
        Flux::toast(variant: 'success', text: 'Projekt wiederhergestellt.');
    }

    public function delete(): void
    {
        Gate::authorize('manage', $this->project);

        $this->validate(['confirmName' => ['required', 'in:'.$this->project->name]], [
            'confirmName.in' => 'Der Name stimmt nicht überein.',
            'confirmName.required' => 'Zur Sicherheit den Projektnamen eintippen.',
        ], ['confirmName' => 'Bestätigung']);

        $this->project->delete();

        $this->redirectRoute('projects.index', navigate: true);
    }
};
?>

<div>
    <flux:modal name="project-settings" class="w-full max-w-lg" x-on:close="$wire.set('confirmName', '')">
        <div class="space-y-8">
            <flux:heading size="lg">Projekt-Einstellungen</flux:heading>

            <form wire:submit="save" class="space-y-4">
                <flux:input wire:model="name" label="Name" />
                <flux:textarea wire:model="description" label="Beschreibung" rows="3" />
                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary">Speichern</flux:button>
                </div>
            </form>

            <flux:separator />

            <div class="space-y-3">
                @if ($project->archived_at)
                    <flux:heading>Archiviert</flux:heading>
                    <flux:text size="sm">Das Projekt ist archiviert: Es taucht nicht mehr in der Projektliste auf und lässt sich nur noch lesen.</flux:text>
                    <flux:button icon="arrow-uturn-left" wire:click="restore">Wiederherstellen</flux:button>
                @else
                    <flux:heading>Archivieren</flux:heading>
                    <flux:text size="sm">Archivierte Projekte verschwinden aus der Projektliste und lassen sich nur noch lesen. Du kannst sie jederzeit wiederherstellen.</flux:text>
                    <flux:button icon="archive-box" wire:click="archive" wire:confirm="Projekt „{{ $project->name }}“ archivieren?">Archivieren</flux:button>
                @endif
            </div>

            <flux:separator />

            <form wire:submit="delete" class="space-y-3">
                <flux:heading class="text-red-600 dark:text-red-400">Projekt löschen</flux:heading>
                <flux:text size="sm">Löscht das Projekt mit allen Aufgaben, Kommentaren und Anhängen endgültig. Tippe zur Bestätigung den Namen <strong>{{ $project->name }}</strong> ein.</flux:text>
                <flux:input wire:model="confirmName" placeholder="{{ $project->name }}" aria-label="Projektname zur Bestätigung" />
                <flux:button type="submit" variant="danger" icon="trash">Endgültig löschen</flux:button>
            </form>
        </div>
    </flux:modal>
</div>
