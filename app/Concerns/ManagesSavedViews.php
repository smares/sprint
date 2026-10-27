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

    /** What opens for this person: '' as the project has set it, 'none', or the id of a view. */
    public string $defaultChoice = '';

    /** What opens for everybody in the project (managers): '' or 'none' for no view, or the id of a shared view. */
    public string $projectChoice = '';

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
        $this->syncDefaultChoices();

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
    public function savedViews(): Collection
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
        $mine = $picked === 0 || $this->savedViews->contains('id', $picked) ? $picked : null;
        $project = $this->savedViews->firstWhere('is_default', true)?->id;

        return ['mine' => $mine, 'project' => $project, 'opens' => $mine === 0 ? null : ($mine ?? $project)];
    }

    private function viewToOpen(): ?SavedFilter
    {
        return $this->savedViews->firstWhere('id', $this->savedViewDefaults['opens']);
    }

    public function saveCurrentView(): void
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
    public function applyView(int $viewId): void
    {
        $saved = $this->visibleView($viewId);

        $this->applyViewSettings($saved->filters);
        Flux::modal('saved-views')->close();
    }

    public function deleteView(int $viewId): void
    {
        $saved = $this->visibleView($viewId)->setRelation('project', $this->project);

        Gate::authorize('delete', $saved);

        $saved->delete();
        $this->forgetSavedViews();
        Flux::toast(variant: 'success', text: __('View deleted.'));
    }

    /**
     * The two menus of what is applied when the page opens: for this person (their own pick comes first), and for everybody in
     * the project (only its managers, and only among the shared views).
     */
    public function saveDefaults(): void
    {
        ['mine' => $mine, 'project' => $project] = $this->savedViewDefaults;

        $chosen = match (true) {
            $this->projectChoice === '' || $this->projectChoice === 'none' => null,
            ctype_digit($this->projectChoice) => (int) $this->projectChoice,
            default => $project,
        };

        // Asked before anything is stored, so a refusal does not leave the personal pick saved
        if ($chosen !== $project) {
            Gate::authorize('manage', $this->project);
        }

        // Anything else than what the menus offer leaves the setting as it is
        $this->rememberMyDefault(match (true) {
            $this->defaultChoice === '' => null,
            $this->defaultChoice === 'none' => 0,
            ctype_digit($this->defaultChoice) => $this->visibleView((int) $this->defaultChoice)->id,
            default => $mine,
        });

        if ($chosen !== $project) {
            $chosen === null ? $this->clearProjectDefault() : $this->makeProjectDefault($chosen);
        }

        $this->forgetSavedViews();
        Flux::toast(variant: 'success', text: __('Setting saved.'));
    }

    private function syncDefaultChoices(): void
    {
        ['mine' => $mine, 'project' => $project] = $this->savedViewDefaults;

        $this->defaultChoice = $mine === null ? '' : ($mine === 0 ? 'none' : (string) $mine);
        $this->projectChoice = $project === null ? 'none' : (string) $project;
    }

    /**
     * @param  int|null  $savedViewId  a view of this page, 0 for none, null to follow the project's default
     */
    private function rememberMyDefault(?int $savedViewId): void
    {
        auth()->user()->rememberDefaultSavedView($this->project->id, $this->savedViewKind(), $savedViewId);

        unset($this->savedViewDefaults);
    }

    /**
     * The shared view is applied by default for everybody in the project (a person's own choice still comes first). The one
     * before it is not any more: there is one per project and kind.
     */
    private function makeProjectDefault(int $viewId): void
    {
        $saved = $this->savedViewsQuery()->whereNull('user_id')->findOrFail($viewId);

        // The project row is locked meanwhile, so two managers at once leave one default, not two
        DB::transaction(function () use ($saved): void {
            Project::query()->whereKey($this->project->getKey())->lockForUpdate()->first();

            $this->savedViewsQuery()->whereKeyNot($saved->getKey())->where('is_default', true)->update(['is_default' => false]);
            $this->savedViewsQuery()->whereKey($saved->getKey())->update(['is_default' => true]);
        });

        unset($this->savedViews, $this->savedViewDefaults);
    }

    private function clearProjectDefault(): void
    {
        $this->savedViewsQuery()->where('is_default', true)->update(['is_default' => false]);

        unset($this->savedViews, $this->savedViewDefaults);
    }

    /**
     * What the dialog shows of a failed save (the missing name) goes when it is closed.
     */
    public function clearSavedViewMessages(): void
    {
        $this->resetValidation();
    }

    private function visibleView(int $viewId): SavedFilter
    {
        return $this->savedViewsQuery()->visibleTo(auth()->user())->findOrFail($viewId);
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
        unset($this->savedViews, $this->savedViewDefaults);

        $this->syncDefaultChoices();
    }
}
