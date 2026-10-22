<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\SavedFilter;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * Saved views of the list (filters and sorting) or of the board (sorting): a person keeps their own, a project's managers
 * can share them with everybody. One view can open by default: the one a person picked for themselves, or else the one the
 * project's managers made the default for everybody. A default applies when the page opens without settings in its address.
 *
 * @property Project $project
 */
trait ManagesSavedViews
{
    public string $saveName = '';

    public bool $saveShared = false;

    /**
     * Whether the saved views belong to the list or to the board (SavedFilter::LIST, SavedFilter::BOARD).
     */
    abstract protected function savedViewKind(): string;

    /**
     * The settings of the page as it is now, to save them.
     *
     * @return array<string, mixed>
     */
    abstract protected function currentViewSettings(): array;

    /**
     * Put saved settings on the page; what no longer exists (a deleted tag, status or person) is skipped.
     *
     * @param  array<string, mixed>  $settings
     */
    abstract protected function applyViewSettings(array $settings): void;

    /**
     * The settings that a page carries in its address: with any of them there, no default is applied.
     *
     * @return list<string>
     */
    abstract protected function savedViewQueryKeys(): array;

    public function mountManagesSavedViews(): void
    {
        foreach ($this->savedViewQueryKeys() as $key) {
            if (request()->query->has($key)) {
                return;
            }
        }

        $default = $this->viewToOpen();

        if ($default instanceof SavedFilter) {
            $this->applyViewSettings($default->filters);
        }
    }

    /**
     * The shared views and the person's own, of this page.
     *
     * @return Collection<int, SavedFilter>
     */
    #[Computed]
    public function savedFilters(): Collection
    {
        return $this->project->savedFilters()->forView($this->savedViewKind())->visibleTo(auth()->user())->orderBy('name')->get()
            ->each(fn (SavedFilter $saved) => $saved->setRelation('project', $this->project));
    }

    /**
     * Which view opens by default: what the person picked (0: none), else what the project made the default for everybody.
     *
     * @return array{mine: ?int, project: ?int}
     */
    #[Computed]
    public function savedViewDefaults(): array
    {
        return [
            'mine' => auth()->user()->defaultSavedView($this->project->id, $this->savedViewKind()),
            'project' => $this->savedFilters->firstWhere('is_default', true)?->id,
        ];
    }

    private function viewToOpen(): ?SavedFilter
    {
        ['mine' => $mine, 'project' => $project] = $this->savedViewDefaults;

        if ($mine === 0) {
            return null;
        }

        // A view that was deleted since the person picked it is as good as none picked
        return $this->savedFilters->firstWhere('id', $mine) ?? $this->savedFilters->firstWhere('id', $project);
    }

    public function saveCurrentFilter(): void
    {
        $validated = $this->validate(['saveName' => ['required', 'string', 'max:80']], attributes: ['saveName' => __('Name')]);

        $shared = $this->saveShared && Gate::allows('manage', $this->project);

        $this->project->savedFilters()->updateOrCreate(
            ['user_id' => $shared ? null : auth()->id(), 'name' => trim($validated['saveName']), 'view' => $this->savedViewKind()],
            ['filters' => $this->currentViewSettings()],
        );

        $this->reset('saveName', 'saveShared');
        $this->forgetSavedViews();
        Flux::toast(variant: 'success', text: __('View saved.'));
    }

    /**
     * Apply a saved view.
     */
    public function applyFilter(int $filterId): void
    {
        $saved = $this->visibleView($filterId);

        $this->applyViewSettings($saved->filters);
        Flux::modal('saved-filters')->close();
    }

    public function deleteFilter(int $filterId): void
    {
        $saved = $this->visibleView($filterId)->setRelation('project', $this->project);

        Gate::authorize('delete', $saved);

        $saved->delete();
        $this->forgetSavedViews();
    }

    /**
     * The saved view opens by default for this person, whatever the project's default is.
     */
    public function useAsMyDefault(int $filterId): void
    {
        $this->rememberMyDefault($this->visibleView($filterId)->id);
    }

    /**
     * This person opens no saved view by default, even if the project has a default.
     */
    public function useNoDefaultForMe(): void
    {
        $this->rememberMyDefault(0);
    }

    /**
     * Back to the project's default (if it has one).
     */
    public function followProjectDefault(): void
    {
        $this->rememberMyDefault(null);
    }

    private function rememberMyDefault(?int $savedViewId): void
    {
        auth()->user()->rememberDefaultSavedView($this->project->id, $this->savedViewKind(), $savedViewId);

        unset($this->savedViewDefaults);
        Flux::toast(variant: 'success', text: __('Default saved.'));
    }

    /**
     * The shared view opens by default for everybody in the project (a person's own default still comes first). The default
     * before it is no default any more: there is one per project and kind.
     */
    public function makeProjectDefault(int $filterId): void
    {
        Gate::authorize('manage', $this->project);

        $saved = $this->project->savedFilters()->forView($this->savedViewKind())->whereNull('user_id')->findOrFail($filterId);

        DB::transaction(function () use ($saved): void {
            $this->project->savedFilters()->forView($this->savedViewKind())->where('is_default', true)->update(['is_default' => false]);
            $saved->update(['is_default' => true]);
        });

        $this->forgetSavedViews();
        Flux::toast(variant: 'success', text: __('Default saved.'));
    }

    public function clearProjectDefault(): void
    {
        Gate::authorize('manage', $this->project);

        $this->project->savedFilters()->forView($this->savedViewKind())->where('is_default', true)->update(['is_default' => false]);

        $this->forgetSavedViews();
        Flux::toast(variant: 'success', text: __('Default saved.'));
    }

    private function visibleView(int $filterId): SavedFilter
    {
        return $this->project->savedFilters()->forView($this->savedViewKind())->visibleTo(auth()->user())->findOrFail($filterId);
    }

    private function forgetSavedViews(): void
    {
        unset($this->savedFilters, $this->savedViewDefaults);
    }
}
