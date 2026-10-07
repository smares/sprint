<?php

use App\Models\Project;
use App\Models\Task;
use App\TaskSearch;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Suche')] class extends Component
{
    #[Url(as: 'q')]
    public string $query = '';

    #[Url]
    public string $projectId = '';

    #[Url]
    public string $state = 'all';

    #[Url]
    public bool $mine = false;

    /**
     * @return list<string>
     */
    #[Computed]
    public function terms(): array
    {
        return app(TaskSearch::class)->terms($this->query);
    }

    #[Computed]
    public function projects()
    {
        return Project::visibleTo(auth()->user())->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function results()
    {
        if ($this->terms === []) {
            return collect();
        }

        return app(TaskSearch::class)
            ->search(auth()->user(), $this->query, [
                'project_id' => $this->projectId,
                'state' => in_array($this->state, ['open', 'done'], true) ? $this->state : 'all',
                'mine' => $this->mine,
            ])
            ->with(['project', 'parent', 'status', 'comments', 'attachments'])
            ->limit(51)
            ->get();
    }

    /**
     * @return array{label: string, text: string}|null
     */
    public function explain(Task $task): ?array
    {
        return app(TaskSearch::class)->explain($task, $this->terms);
    }
};
?>

<div class="max-w-4xl">
    <flux:heading size="xl" class="mb-6">Suche</flux:heading>

    <div class="space-y-4">
        <flux:input wire:model.live.debounce.300ms="query" type="search" icon="magnifying-glass" placeholder="Titel, Beschreibung, Kommentare, Anhänge …" autofocus clearable />

        <div class="grid gap-4 sm:grid-cols-3">
            <flux:select variant="listbox" wire:model.live="projectId" label="Projekt">
                <flux:select.option value="">Alle Projekte</flux:select.option>
                @foreach ($this->projects as $project)
                    <flux:select.option value="{{ $project->id }}">{{ $project->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select variant="listbox" wire:model.live="state" label="Status">
                <flux:select.option value="all">Offen und erledigt</flux:select.option>
                <flux:select.option value="open">Nur offene</flux:select.option>
                <flux:select.option value="done">Nur erledigte</flux:select.option>
            </flux:select>
            <div class="flex items-end pb-2">
                <flux:switch wire:model.live="mine" label="Nur meine Aufgaben" />
            </div>
        </div>
    </div>

    <div class="mt-8">
        @if ($this->terms === [])
            <flux:text>Gib einen Suchbegriff ein. Alle Wörter müssen vorkommen, Wortanfänge reichen.</flux:text>
        @elseif ($this->results->isEmpty())
            <flux:callout icon="magnifying-glass" heading="Nichts gefunden" text="Zu „{{ $query }}“ gibt es keine passenden Aufgaben, die du sehen darfst." />
        @else
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->results->take(50) as $task)
                    @php($hit = $this->explain($task))
                    <li wire:key="result-{{ $task->id }}" class="py-3">
                        <div class="flex items-center gap-2">
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="font-medium hover:underline">{{ $task->title }}</a>
                            <flux:badge size="sm" :color="$task->status->color">{{ $task->status->name }}</flux:badge>
                        </div>
                        <flux:text size="sm">
                            {{ $task->project->name }}@if ($task->parent) · in {{ $task->parent->title }}@endif
                            @if ($task->due_date) · fällig {{ $task->due_date->format('d.m.Y') }}@endif
                        </flux:text>
                        @if ($hit)
                            <flux:text size="sm" class="mt-1"><span class="font-medium">{{ $hit['label'] }}:</span> {{ $hit['text'] }}</flux:text>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($this->results->count() > 50)
                <flux:text size="sm" class="mt-3">Es gibt mehr als 50 Treffer – grenze die Suche ein.</flux:text>
            @endif
        @endif
    </div>
</div>
