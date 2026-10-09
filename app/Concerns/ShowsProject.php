<?php

namespace App\Concerns;

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

/**
 * What the list, board, calendar and timeline of a project share: only people who may see the project
 * get in (checked on every request), what they may do, who else is here, a fresh render after a new
 * task, and the visit for the recent projects. The component declares `public Project $project`.
 */
trait ShowsProject
{
    use ListensForRealtime;

    public function mountShowsProject(): void
    {
        Gate::authorize('view', $this->project);

        // The command palette offers the recently opened projects
        if (auth()->user()->rememberProjectVisit($this->project)) {
            $this->dispatch('project-visited');
        }
    }

    public function hydrateShowsProject(): void
    {
        Gate::authorize('view', $this->project);
    }

    #[Computed]
    public function canEdit(): bool
    {
        return Gate::allows('edit', $this->project);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage', $this->project);
    }

    public function presenceChannel(): string
    {
        return "project.{$this->project->getKey()}.presence";
    }

    /**
     * A task was created in the dialog of the project header; rendering again shows it.
     */
    #[On('task-created')]
    public function taskCreated(): void {}
}
