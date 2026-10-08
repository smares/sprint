<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
| Who may listen: the people who may open the project, the task or their own inbox.
*/

Broadcast::channel('project.{project}', fn (User $user, Project $project) => Gate::forUser($user)->allows('view', $project));

Broadcast::channel('project.{project}.presence', fn (User $user, Project $project) => Gate::forUser($user)->allows('view', $project)
    ? ['id' => $user->id, 'name' => $user->name, 'initials' => $user->initials()]
    : false);

Broadcast::channel('task.{task}.presence', fn (User $user, Task $task) => Gate::forUser($user)->allows('view', $task->project)
    ? ['id' => $user->id, 'name' => $user->name, 'initials' => $user->initials()]
    : false);

Broadcast::channel('user.{id}', fn (User $user, int $id) => $user->id === $id);
