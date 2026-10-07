<?php

use App\Models\Project;
use App\ProjectRole;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Projekte')] class extends Component
{
    public string $name = '';

    public string $description = '';

    #[Computed]
    public function projects()
    {
        return Project::query()
            ->visibleTo(auth()->user())
            ->whereNull('archived_at')
            ->withCount([
                'tasks',
                'tasks as open_tasks_count' => fn ($query) => $query->whereHas('status', fn ($status) => $status->where('is_done', false)),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * Archived projects the person can still open (read-only).
     */
    #[Computed]
    public function archivedProjects()
    {
        return Project::query()
            ->visibleTo(auth()->user())
            ->whereNotNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'archived_at']);
    }

    public function create(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $project = Project::create($validated);
        $project->setRole(auth()->user(), ProjectRole::Admin);

        $this->reset('name', 'description');
        Flux::modal('create-project')->close();

        $this->redirectRoute('projects.show', $project, navigate: true);
    }
};
?>

<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl">Projekte</flux:heading>

        <flux:modal.trigger name="create-project">
            <flux:button variant="primary" icon="plus">Neues Projekt</flux:button>
        </flux:modal.trigger>
    </div>

    @if ($this->projects->isEmpty())
        <flux:callout icon="folder-open" heading="Noch keine Projekte" text="Lege das erste Projekt an, um Aufgaben zu verwalten." />
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->projects as $project)
                <a href="{{ route('projects.show', $project) }}" wire:navigate wire:key="project-{{ $project->id }}">
                    <flux:card class="h-full hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <flux:heading size="lg">{{ $project->name }}</flux:heading>
                        <flux:text class="mt-1 line-clamp-2">{{ $project->description }}</flux:text>
                        <div class="mt-4 flex gap-2">
                            <flux:badge color="blue">{{ $project->open_tasks_count }} offen</flux:badge>
                            <flux:badge>{{ $project->tasks_count }} gesamt</flux:badge>
                        </div>
                    </flux:card>
                </a>
            @endforeach
        </div>
    @endif

    @if ($this->archivedProjects->isNotEmpty())
        <details class="mt-10">
            <summary class="cursor-pointer text-sm font-medium text-zinc-500 dark:text-zinc-400">Archivierte Projekte ({{ $this->archivedProjects->count() }})</summary>
            <ul class="mt-3 space-y-1">
                @foreach ($this->archivedProjects as $archived)
                    <li wire:key="archived-{{ $archived->id }}">
                        <a href="{{ route('projects.show', $archived) }}" wire:navigate class="hover:underline">{{ $archived->name }}</a>
                        <flux:text size="sm" class="ms-2 inline">archiviert am {{ $archived->archived_at->format('d.m.Y') }}</flux:text>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <flux:modal name="create-project" class="md:w-96">
        <form wire:submit="create" class="space-y-6">
            <flux:heading size="lg">Neues Projekt</flux:heading>
            <flux:input wire:model="name" label="Name" autofocus />
            <flux:textarea wire:model="description" label="Beschreibung" rows="3" />
            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary">Anlegen</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
