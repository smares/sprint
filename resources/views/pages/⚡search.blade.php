<?php

use App\Models\Project;
use App\Models\Task;
use App\Services\TaskSearchService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    private const PAGE_SIZE = 50;

    #[Locked]
    public int $limit = self::PAGE_SIZE;

    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    /**
     * A new query or filter starts again at the first page.
     */
    public function updated(string $name): void
    {
        if ($name !== 'limit') {
            $this->limit = self::PAGE_SIZE;
        }
    }

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
        return app(TaskSearchService::class)->terms($this->query);
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

        return app(TaskSearchService::class)
            ->search(auth()->user(), $this->query, [
                'project_id' => $this->projectId,
                'state' => in_array($this->state, ['open', 'done'], true) ? $this->state : 'all',
                'mine' => $this->mine,
            ])
            ->with(['project', 'parent', 'status', 'comments', 'attachments'])
            ->limit($this->limit + 1)
            ->get();
    }

    /**
     * @return array{label: string, text: string}|null
     */
    public function explain(Task $task): ?array
    {
        return app(TaskSearchService::class)->explain($task, $this->terms);
    }

    public function rendering($view): void
    {
        $view->title(__('Search'));
    }
};
?>

<div class="max-w-4xl">
    <flux:heading size="xl" class="mb-6">{{ __('Search') }}</flux:heading>

    <div class="space-y-4">
        <flux:input wire:model.live.debounce.300ms="query" type="search" icon="magnifying-glass" :placeholder="__('Title, description, comments, attachments …')" autofocus clearable />

        <div class="grid gap-4 sm:grid-cols-3">
            <flux:select variant="listbox" wire:model.live="projectId" :label="__('Project')">
                <flux:select.option value="">{{ __('All projects') }}</flux:select.option>
                @foreach ($this->projects as $project)
                    <flux:select.option value="{{ $project->id }}">{{ $project->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select variant="listbox" wire:model.live="state" :label="__('Status')">
                <flux:select.option value="all">{{ __('Open and done') }}</flux:select.option>
                <flux:select.option value="open">{{ __('Open only') }}</flux:select.option>
                <flux:select.option value="done">{{ __('Done only') }}</flux:select.option>
            </flux:select>
            <div class="flex items-end pb-2">
                <flux:switch wire:model.live="mine" :label="__('Only my tasks')" />
            </div>
        </div>
    </div>

    <div class="mt-8">
        @if ($this->terms === [])
            <flux:text>{{ __('Enter a search term. All words must appear; word beginnings are enough.') }}</flux:text>
        @elseif ($this->results->isEmpty())
            <flux:callout icon="magnifying-glass" :heading="__('No results found')" :text="__('There are no matching tasks for “:query” that you can see.', ['query' => $query])" />
        @else
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->results->take($this->limit) as $task)
                    @php($hit = $this->explain($task))
                    <li wire:key="result-{{ $task->id }}" class="py-3">
                        <div class="flex items-center gap-2">
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="font-medium hover:underline">{{ $task->title }}</a>
                            <x-color-badge size="sm" :color="$task->status->color">{{ $task->status->name }}</x-color-badge>
                        </div>
                        <flux:text size="sm">
                            {{ $task->project->name }}@if ($task->parent) · {{ __('in :title', ['title' => $task->parent->title]) }}@endif
                            @if ($task->due_date) · {{ __('due :date', ['date' => $task->due_date->isoFormat('L')]) }}@endif
                        </flux:text>
                        @if ($hit)
                            <flux:text size="sm" class="mt-1"><span class="font-medium">{{ $hit['label'] }}:</span> {{ $hit['text'] }}</flux:text>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($this->results->count() > $this->limit)
                <div wire:intersect="loadMore" class="mt-4 flex items-center justify-center gap-3">
                    <flux:text size="sm">{{ __(':count results shown, there are more.', ['count' => $this->limit]) }}</flux:text>
                    <flux:button size="sm" wire:click="loadMore">{{ __('Load more') }}</flux:button>
                </div>
            @endif
        @endif
    </div>
</div>
