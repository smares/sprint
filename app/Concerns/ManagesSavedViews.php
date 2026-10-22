<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\SavedFilter;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * The settings that a page carries in its address, by their name there: the property of the page that holds each.
     *
     * @return array<string, string>
     */
    abstract protected function savedViewQueryProperties(): array;

    /**
     * Opens the default view, if there is one. What the address says comes before it, setting by setting: a link with a
     * filter keeps that filter and still has the rest of the default, and a page that was changed after it opened (Livewire
     * writes only the changes to the address) comes back the same way.
     */
    public function mountManagesSavedViews(): void
    {
        $default = $this->viewToOpen();

        if (! $default instanceof SavedFilter) {
            return;
        }

        $fromAddress = [];

        foreach ($this->savedViewQueryProperties() as $key => $property) {
            if (request()->query->has($key)) {
                $fromAddress[$property] = $this->{$property};
            }
        }

        $this->applyViewSettings($default->filters);

        foreach ($fromAddress as $property => $value) {
            $this->{$property} = $value;
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
        return $this->savedViewsQuery()->visibleTo(auth()->user())->orderBy('name')->get()
            ->each(fn (SavedFilter $saved) => $saved->setRelation('project', $this->project));
    }

    /**
     * Which view opens by default: `mine` is what the person picked (0: none) if that view still exists, `project` what the
     * project made the default for everybody, `opens` the one that opens for this person (their own pick comes first).
     *
     * @return array{mine: ?int, project: ?int, opens: ?int}
     */
    #[Computed]
    public function savedViewDefaults(): array
    {
        $picked = auth()->user()->defaultSavedView($this->project->id, $this->savedViewKind());

        // A view that was deleted since the person picked it is as good as none picked
        $mine = $picked === 0 || $this->savedFilters->contains('id', $picked) ? $picked : null;
        $project = $this->savedFilters->firstWhere('is_default', true)?->id;

        return ['mine' => $mine, 'project' => $project, 'opens' => $mine === 0 ? null : ($mine ?? $project)];
    }

    private function viewToOpen(): ?SavedFilter
    {
        return $this->savedFilters->firstWhere('id', $this->savedViewDefaults['opens']);
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
        $this->rememberMyDefault(null, __('Default removed.'));
    }

    private function rememberMyDefault(?int $savedViewId, ?string $message = null): void
    {
        auth()->user()->rememberDefaultSavedView($this->project->id, $this->savedViewKind(), $savedViewId);

        unset($this->savedViewDefaults);
        Flux::toast(variant: 'success', text: $message ?? __('Default saved.'));
    }

    /**
     * The shared view opens by default for everybody in the project (a person's own default still comes first). The default
     * before it is no default any more: there is one per project and kind.
     */
    public function makeProjectDefault(int $filterId): void
    {
        Gate::authorize('manage', $this->project);

        $saved = $this->savedViewsQuery()->whereNull('user_id')->findOrFail($filterId);

        // The project row is locked meanwhile, so two managers at once leave one default, not two
        DB::transaction(function () use ($saved): void {
            Project::query()->whereKey($this->project->getKey())->lockForUpdate()->first();

            $this->savedViewsQuery()->whereKeyNot($saved->getKey())->where('is_default', true)->update(['is_default' => false]);
            $this->savedViewsQuery()->whereKey($saved->getKey())->update(['is_default' => true]);
        });

        $this->forgetSavedViews();
        Flux::toast(variant: 'success', text: __('Default saved.'));
    }

    public function clearProjectDefault(): void
    {
        Gate::authorize('manage', $this->project);

        $this->savedViewsQuery()->where('is_default', true)->update(['is_default' => false]);

        $this->forgetSavedViews();
        Flux::toast(variant: 'success', text: __('Default removed.'));
    }

    private function visibleView(int $filterId): SavedFilter
    {
        return $this->savedViewsQuery()->visibleTo(auth()->user())->findOrFail($filterId);
    }

    /**
     * @return HasMany<SavedFilter, Project>
     */
    private function savedViewsQuery(): HasMany
    {
        return $this->project->savedFilters()->forView($this->savedViewKind());
    }

    private function forgetSavedViews(): void
    {
        unset($this->savedFilters, $this->savedViewDefaults);
    }
}
