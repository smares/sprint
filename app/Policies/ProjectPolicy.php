<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\ProjectRole;

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
     * Change tasks, comments, tags and the order of things.
     */
    public function edit(User $user, Project $project): bool
    {
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
