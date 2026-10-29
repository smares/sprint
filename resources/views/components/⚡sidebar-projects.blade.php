<?php

use App\Enums\ProjectView;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /** How many recently opened projects follow the favorites. */
    public const int RECENT_PROJECTS = 5;

    /** The project the page belongs to (a project view or one of its tasks), whose views are listed under it. */
    #[Locked]
    public ?int $currentProjectId = null;

    /** The view the page shows, highlighted under the current project. */
    #[Locked]
    public ?string $currentView = null;

    public function mount(): void
    {
        $route = request()->route();
        $project = $route?->parameter('project');
        $task = $route?->parameter('task');

        $this->currentProjectId = match (true) {
            $project instanceof Project => $project->id,
            $task instanceof Task => $task->project_id,
            default => null,
        };
        $this->currentView = ProjectView::forRoute($route?->getName())?->value;
    }

    /**
     * The favorites in the person's own order, then the projects opened last, and the current project in any case
     * (at the end when it is neither), all open to the person. Archived ones only show while open, so their views stay
     * reachable on large screens, where the sidebar is the only way to switch them.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        $user = auth()->user();
        $favoriteIds = $this->favoriteIds;
        $recentIds = collect($user->recentProjectIds())->diff($favoriteIds)->take(self::RECENT_PROJECTS)->values()->all();
        $ids = collect([...$favoriteIds, ...$recentIds, $this->currentProjectId])->filter()->unique()->values()->all();

        $projects = Project::query()
            ->visibleTo($user)
            ->whereKey($ids)
            ->where(fn (Builder $query) => $query->whereNull('archived_at')->orWhereKey($this->currentProjectId))
            ->get(['id', 'name', 'color', 'icon', 'archived_at']);
        $order = array_flip($ids);

        return $projects->sortBy(fn (Project $project) => $order[$project->id])->values();
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function favoriteIds(): array
    {
        return auth()->user()->favoriteProjectIds();
    }

    /**
     * Built again when favorites or projects change elsewhere. A visit needs nothing: the sidebar is drawn anew with
     * every page, after the page has noted the visit.
     */
    #[On('favorites-changed')]
    #[On('project-updated')]
    public function refreshProjects(): void
    {
        unset($this->projects, $this->favoriteIds);
    }
};
?>

{{-- The projects in the sidebar: the favorites, then the recently opened ones (and the current project in any case), each
     with its tile, which is all that shows when the sidebar is collapsed. Under the current project its views, to switch
     between list, board and the others (on large screens the only place; below lg the header has them too). --}}
@php
    [$favorites, $others] = $this->projects->partition(fn ($project) => in_array($project->id, $this->favoriteIds, true));
    $groups = array_filter([__('Favorites') => $favorites, __('Recently opened') => $others], fn ($projects) => $projects->isNotEmpty());
@endphp
<flux:sidebar.nav>
    @forelse ($groups as $heading => $projects)
        <div class="flex items-center justify-between px-3 pb-1 pt-3 in-data-flux-sidebar-collapsed-desktop:hidden">
            <flux:text size="sm" class="font-medium">{{ $heading }}</flux:text>
            @if ($loop->first)
                <flux:link :href="route('projects.index')" wire:navigate variant="subtle" class="text-xs max-sm:-my-2.5 max-sm:flex max-sm:min-h-10 max-sm:items-center max-sm:px-2">{{ __('All projects') }}</flux:link>
            @endif
        </div>

        @foreach ($projects as $project)
            @php
                $isCurrent = $project->id === $currentProjectId;
                $views = $isCurrent ? ProjectView::links($project, ProjectView::tryFrom($currentView ?? '')) : [];
            @endphp
            <flux:sidebar.item wire:key="sidebar-project-{{ $project->id }}" :href="route('projects.show', $project)" wire:navigate :current="$isCurrent && $currentView === null" data-sidebar-project="{{ $project->id }}" :badge="$project->archived_at ? __('Archived') : null">
                <x-slot name="icon">
                    <x-project-icon :project="$project" size="sm" />
                </x-slot>
                {{ $project->name }}
            </flux:sidebar.item>

            @if ($isCurrent)
                <x-sidebar-views :views="$views" :label="$project->name" />
            @endif
        @endforeach
    @empty
        <flux:sidebar.item icon="folder-open" :href="route('projects.index')" wire:navigate>{{ __('All projects') }}</flux:sidebar.item>
    @endforelse
</flux:sidebar.nav>
