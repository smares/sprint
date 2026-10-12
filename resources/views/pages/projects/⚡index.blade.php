<?php

use App\Enums\ProjectRole;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public string $description = '';

    #[Computed]
    public function projects(): Collection
    {
        return Project::query()
            ->visibleTo(auth()->user())
            ->whereNull('archived_at')
            ->withCount([
                'tasks as open_tasks_count' => fn ($query) => $query->open(),
                // Like the list, which these numbers open: top-level tasks only
                'tasks as overdue_tasks_count' => fn ($query) => $query->topLevel()->overdue(),
                'tasks as due_soon_tasks_count' => fn ($query) => $query->topLevel()->dueSoon(),
                // The progress bar: top-level tasks (sections aside) and how many of them are done
                'tasks as progress_total_count' => fn ($query) => $query->topLevel()->where('tasks.is_section', false),
                'tasks as progress_done_count' => fn ($query) => $query->topLevel()->where('tasks.is_section', false)->done(),
            ])
            ->withMin(['tasks as next_due_date' => fn ($query) => $query->open()->where('tasks.due_date', '>=', today()->toDateString())], 'due_date')
            ->with('members')
            ->orderBy('name')
            ->get();
    }

    /**
     * The person's starred projects that are not archived, in their own order.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function favorites(): Collection
    {
        $order = auth()->user()->favoriteProjects()->pluck('projects.id')->flip();

        return $this->projects->filter(fn (Project $project) => $order->has($project->id))
            ->sortBy(fn (Project $project) => $order[$project->id])
            ->values();
    }

    public function toggleFavorite(int $projectId): void
    {
        $project = Project::query()->visibleTo(auth()->user())->findOrFail($projectId);
        $favorites = auth()->user()->favoriteProjects();

        if ($favorites->whereKey($project->id)->exists()) {
            auth()->user()->favoriteProjects()->detach($project->id);
        } else {
            auth()->user()->favoriteProjects()->attach($project->id, ['position' => (int) auth()->user()->favoriteProjects()->max('position') + 1]);
        }

        unset($this->favorites);
        $this->dispatch('favorites-changed');
    }

    /**
     * Drag and drop among the favorites; only the person's own order changes.
     */
    public function moveFavorite(int|string $projectId, int $position): void
    {
        $ids = $this->favorites->pluck('id')->all();
        $index = array_search((int) $projectId, $ids, true);

        if ($index === false) {
            return;
        }

        array_splice($ids, $index, 1);
        array_splice($ids, max(0, min($position, count($ids))), 0, [(int) $projectId]);

        foreach ($ids as $order => $id) {
            auth()->user()->favoriteProjects()->updateExistingPivot($id, ['position' => $order]);
        }

        unset($this->favorites);
        $this->dispatch('favorites-changed');
    }

    /**
     * Archived projects the person can still open (read-only).
     */
    #[Computed]
    public function archivedProjects(): Collection
    {
        return Project::query()
            ->visibleTo(auth()->user())
            ->whereNotNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'color', 'icon', 'archived_at']);
    }

    public function create(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ], attributes: ['name' => __('Name'), 'description' => __('Description')]);

        $project = Project::create($validated);
        $project->setRole(auth()->user(), ProjectRole::Admin);

        $this->reset('name', 'description');
        Flux::modal('create-project')->close();
        $this->dispatch('project-updated');

        $this->redirectRoute('projects.show', $project, navigate: true);
    }

    public function rendering(View $view): void
    {
        $view->title(__('Projects'));
    }
};
?>

{{-- Starring changes only the favorites: the stars follow at once in the browser, and only the favorites (an island) are
     drawn again, not every card; the sidebar and the command palette update through favorites-changed. --}}
<div x-data="{
    favorites: @js($this->favorites->pluck('id')->all()),
    toggleFavorite(id) {
        this.favorites = this.favorites.includes(id) ? this.favorites.filter((other) => other !== id) : [...this.favorites, id]
        this.$wire.$island('favorites').toggleFavorite(id)
    },
}">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl">{{ __('Projects') }}</flux:heading>

        <flux:modal.trigger name="create-project">
            <flux:button variant="primary" icon="plus">{{ __('New project') }}</flux:button>
        </flux:modal.trigger>
    </div>

    @if ($this->projects->isEmpty())
        <x-empty-state emoji="🚀" :heading="__('No projects yet')" :text="__('Create your first project to start managing tasks.')">
            <flux:modal.trigger name="create-project">
                <flux:button variant="primary" icon="plus">{{ __('New project') }}</flux:button>
            </flux:modal.trigger>
        </x-empty-state>
    @else
        @island(name: 'favorites')
            @if ($this->favorites->isNotEmpty())
                <flux:heading size="lg" class="mb-3">{{ __('Favorites') }}</flux:heading>
                {{-- Each person sorts their own favorites by dragging --}}
                <div class="mb-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" wire:sort="moveFavorite" wire:sort:config="{ delay: 250, delayOnTouchOnly: true, touchStartThreshold: 12 }">
                    @foreach ($this->favorites as $project)
                        <x-project-card :project="$project" wire:key="favorite-{{ $project->id }}" wire:sort:item="{{ $project->id }}" />
                    @endforeach
                </div>

                <flux:heading size="lg" class="mb-3">{{ __('All projects') }}</flux:heading>
            @else
                <flux:text class="mb-4">{{ __('Star a project to keep it at the top as a favorite.') }}</flux:text>
            @endif
        @endisland

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->projects as $project)
                <x-project-card :project="$project" wire:key="project-{{ $project->id }}" />
            @endforeach
        </div>
    @endif

    @if ($this->archivedProjects->isNotEmpty())
        <flux:accordion transition class="mt-10">
            <flux:accordion.item>
                <flux:accordion.heading>{{ __('Archived projects (:count)', ['count' => $this->archivedProjects->count()]) }}</flux:accordion.heading>
                <flux:accordion.content>
                    <ul class="space-y-1">
                        @foreach ($this->archivedProjects as $archived)
                            <li wire:key="archived-{{ $archived->id }}" class="flex flex-wrap items-center gap-x-2">
                                <x-project-icon :project="$archived" size="sm" class="opacity-60" />
                                <flux:link :href="route('projects.show', $archived)" variant="ghost" wire:navigate>{{ $archived->name }}</flux:link>
                                <flux:text size="sm" class="inline">{{ __('archived on :date', ['date' => $archived->archived_at->isoFormat('L')]) }}</flux:text>
                            </li>
                        @endforeach
                    </ul>
                </flux:accordion.content>
            </flux:accordion.item>
        </flux:accordion>
    @endif

    <flux:modal name="create-project" class="md:w-96">
        <form wire:submit="create" class="space-y-6">
            <flux:heading size="lg">{{ __('New project') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" autofocus />
            <flux:textarea wire:model="description" :label="__('Description')" rows="3" />
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
