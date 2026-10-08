<?php

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Open the project and read everything in it.
     */
    public function view(User $user, Project $project): bool
    {
        return $project->canBeViewedBy($user);
    }

    /**
     * Change tasks, comments, tags and the order of things; archived projects are read-only.
     */
    public function edit(User $user, Project $project): bool
    {
        if ($project->archived_at !== null) {
            return false;
        }

        return $project->roleFor($user)?->atLeast(ProjectRole::Editor) ?? false;
    }

    /**
     * Change the project itself: members and statuses.
     */
    public function manage(User $user, Project $project): bool
    {
        return $project->roleFor($user)?->atLeast(ProjectRole::Admin) ?? false;
    }
}
