<?php

namespace App\Models;

use App\ProjectRole;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'description', 'archived_at'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (self $project) {
            $project->statuses()->createMany(TaskStatus::defaults());
        });
    }

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->withPivot('role')->withTimestamps()->orderBy('users.name');
    }

    /**
     * What the person may do here: app admins manage every project, everybody else needs a membership.
     */
    public function roleFor(?User $user): ?ProjectRole
    {
        if ($user === null) {
            return null;
        }

        if ($user->is_admin) {
            return ProjectRole::Admin;
        }

        $role = $this->members()->whereKey($user->getKey())->first()?->pivot->role;

        return $role === null ? null : ProjectRole::from($role);
    }

    public function canBeViewedBy(?User $user): bool
    {
        return $this->roleFor($user) !== null;
    }

    public function setRole(User $user, ProjectRole $role): void
    {
        $this->members()->syncWithoutDetaching([$user->getKey() => ['role' => $role->value]]);
    }

    /**
     * Projects the person may open.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->is_admin) {
            return;
        }

        $query->whereHas('members', fn (Builder $members) => $members->whereKey($user->getKey()));
    }

    /**
     * People who can be assigned, mentioned or made collaborators: members and application admins.
     */
    public function eligibleUsers(): Builder
    {
        return User::query()
            ->where(fn (Builder $users) => $users
                ->where('is_admin', true)
                ->orWhereHas('projects', fn (Builder $projects) => $projects->whereKey($this->getKey()))
            );
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(TaskStatus::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The status new tasks start with: the first one that does not count as done.
     */
    public function defaultStatus(): TaskStatus
    {
        return $this->statuses()->where('is_done', false)->firstOrFail();
    }

    /**
     * The status a task gets when it is marked as done.
     */
    public function doneStatus(): TaskStatus
    {
        return $this->statuses()->where('is_done', true)->firstOrFail();
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Done and total counts of all descendants for every task that has subtasks.
     *
     * @return array<int, array{done: int, total: int}>
     */
    public function subtaskProgress(): array
    {
        $tasks = $this->tasks()->where('is_section', false)->with('status:id,is_done')->get(['id', 'parent_id', 'status_id']);
        $childrenByParent = $tasks->groupBy('parent_id');
        $progress = [];

        $count = function (int $id) use (&$count, &$progress, $childrenByParent): array {
            $done = 0;
            $total = 0;

            foreach ($childrenByParent->get($id, []) as $child) {
                [$childDone, $childTotal] = $count($child->id);
                $total += 1 + $childTotal;
                $done += ($child->isDone() ? 1 : 0) + $childDone;
            }

            if ($total > 0) {
                $progress[$id] = ['done' => $done, 'total' => $total];
            }

            return [$done, $total];
        };

        foreach ($childrenByParent->get(null, []) as $root) {
            $count($root->id);
        }

        return $progress;
    }

    /**
     * Next free position among the top-level tasks.
     */
    public function nextRootPosition(): int
    {
        return ($this->tasks()->whereNull('parent_id')->max('position') ?? -1) + 1;
    }

    /**
     * Put a top-level task into the single manual order shared by list and board.
     *
     * @param  list<int>  $visibleIds  Ordered ids currently shown to the user, without the moved task.
     */
    public function placeRootTask(Task $task, array $visibleIds, int $position): void
    {
        DB::transaction(function () use ($task, $visibleIds, $position) {
            $orderedIds = $this->tasks()
                ->whereNull('parent_id')
                ->whereKeyNot($task->getKey())
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $visibleIds = array_values(array_intersect($visibleIds, $orderedIds));
            $position = max(0, min($position, count($visibleIds)));

            $insertAt = match (true) {
                $visibleIds === [] => count($orderedIds),
                $position < count($visibleIds) => array_search($visibleIds[$position], $orderedIds, true),
                default => array_search(end($visibleIds), $orderedIds, true) + 1,
            };

            array_splice($orderedIds, $insertAt, 0, [$task->getKey()]);

            foreach ($orderedIds as $index => $id) {
                Task::whereKey($id)->update(['position' => $index]);
            }
        });
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
