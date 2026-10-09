<?php

namespace App\Models;

use App\Color;
use App\Enums\CustomFieldType;
use App\Enums\ProjectRole;
use App\Services\TaskSearchService;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'description', 'archived_at'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /** @var array<int, ProjectRole|null> Roles already looked up, by user id. */
    private array $knownRoles = [];

    /** @var array<int, bool> Whether people may open the project, already looked up by viewerIds(). */
    private array $knownViewers = [];

    protected static function booted(): void
    {
        static::deleting(function (self $project) {
            $taskIds = $project->tasks()->pluck('id')->all();

            foreach (array_chunk($taskIds, 500) as $chunk) {
                Storage::disk()->delete(Attachment::whereIn('task_id', $chunk)->pluck('path')->all());
            }

            app(TaskSearchService::class)->forgetMany($taskIds);
        });

        static::created(function (self $project) {
            $project->statuses()->createMany(TaskStatus::defaults());

            $priority = CustomField::priorityDefaults();
            $field = $project->customFields()->create([
                'name' => $priority['name'],
                'type' => CustomFieldType::Select,
                'position' => 0,
            ]);
            $field->options()->createMany(array_map(
                fn (array $option, int $position) => $option + ['position' => $position],
                $priority['options'],
                array_keys($priority['options']),
            ));
        });
    }

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->withPivot('role')->withTimestamps()->orderBy('users.name');
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'project_team')->withPivot('role')->withTimestamps()->orderBy('teams.name');
    }

    /**
     * What the person may do here: app admins manage every project, everybody else needs a membership.
     * Every permission check asks this, so the answer is kept for the lifetime of this model instance
     * (one request) and forgotten when memberships change through this model.
     */
    public function roleFor(?User $user): ?ProjectRole
    {
        if (! $user instanceof User) {
            return null;
        }

        if ($user->is_admin) {
            return ProjectRole::Admin;
        }

        if (! array_key_exists($user->getKey(), $this->knownRoles)) {
            $this->knownRoles[$user->getKey()] = $this->lookUpRole($user);
        }

        return $this->knownRoles[$user->getKey()];
    }

    /**
     * Look up the person's role in many projects at once (two queries) and keep it for roleFor().
     *
     * @param  EloquentCollection<int, self>  $projects
     */
    public static function rememberRolesFor(User $user, EloquentCollection $projects): void
    {
        if ($user->is_admin || $projects->isEmpty()) {
            return;
        }

        $ids = $projects->modelKeys();
        $assignments = DB::table('project_members')
            ->where('user_id', $user->getKey())
            ->whereIn('project_id', $ids)
            ->get(['project_id', 'role'])
            ->concat(DB::table('project_team')
                ->join('team_user', 'team_user.team_id', '=', 'project_team.team_id')
                ->where('team_user.user_id', $user->getKey())
                ->whereIn('project_team.project_id', $ids)
                ->get(['project_team.project_id', 'project_team.role']))
            ->groupBy('project_id');

        foreach ($projects as $project) {
            $project->knownRoles[$user->getKey()] = collect($assignments->get($project->getKey(), []))
                ->map(fn (object $assignment) => ProjectRole::from($assignment->role))
                ->sortByDesc(fn (ProjectRole $role) => $role->level())
                ->first();
        }
    }

    private function lookUpRole(User $user): ?ProjectRole
    {
        $roles = $this->teams()
            ->whereHas('users', fn (Builder $users) => $users->whereKey($user->getKey()))
            ->get()
            ->map(fn (Team $team) => $team->pivot->role);

        $direct = $this->members()->whereKey($user->getKey())->first()?->pivot->role;

        return $roles->push($direct)
            ->filter()
            ->map(fn (string $role) => ProjectRole::from($role))
            ->sortByDesc(fn (ProjectRole $role) => $role->level())
            ->first();
    }

    public function canBeViewedBy(?User $user): bool
    {
        return $this->roleFor($user) instanceof ProjectRole;
    }

    /**
     * Which of the given people may open the project, found with three queries instead of two per person.
     *
     * @param  Collection<int, User>  $users
     * @return list<int>
     */
    public function viewerIds(Collection $users): array
    {
        $unknown = $users->reject(fn (User $user) => $user->is_admin || array_key_exists($user->id, $this->knownViewers));

        if ($unknown->isNotEmpty()) {
            $viewers = $this->lookUpViewerIds($unknown->pluck('id'));

            foreach ($unknown as $user) {
                $this->knownViewers[$user->id] = in_array($user->id, $viewers, true);
            }
        }

        return $users
            ->filter(fn (User $user) => $user->is_admin || $this->knownViewers[$user->id])
            ->pluck('id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return list<int>
     */
    private function lookUpViewerIds(Collection $ids): array
    {
        return collect()
            ->merge(DB::table('project_members')->where('project_id', $this->getKey())->whereIn('user_id', $ids)->pluck('user_id'))
            ->merge(
                DB::table('team_user')
                    ->join('project_team', 'project_team.team_id', '=', 'team_user.team_id')
                    ->where('project_team.project_id', $this->getKey())
                    ->whereIn('team_user.user_id', $ids)
                    ->pluck('team_user.user_id')
            )
            ->map(fn (mixed $id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function setTeamRole(Team $team, ProjectRole $role): void
    {
        $this->teams()->syncWithoutDetaching([$team->getKey() => ['role' => $role->value]]);
        $this->forgetAccess();
    }

    public function removeTeam(Team $team): void
    {
        $this->teams()->detach($team->getKey());
        $this->forgetAccess();
    }

    public function setRole(User $user, ProjectRole $role): void
    {
        $this->members()->syncWithoutDetaching([$user->getKey() => ['role' => $role->value]]);
        $this->forgetAccess();
    }

    public function removeMember(User $user): void
    {
        $this->members()->detach($user->getKey());
        $this->forgetAccess();
    }

    private function forgetAccess(): void
    {
        $this->knownRoles = [];
        $this->knownViewers = [];
    }

    /**
     * Projects the person may open.
     *
     * @param  Builder<static>  $query
     */
    protected function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->is_admin) {
            return;
        }

        $query->where(fn (Builder $projects) => $projects
            ->whereHas('members', fn (Builder $members) => $members->whereKey($user->getKey()))
            ->orWhereHas('teams.users', fn (Builder $users) => $users->whereKey($user->getKey()))
        );
    }

    /**
     * People who can be assigned, mentioned or made collaborators: members and application admins.
     *
     * @return Builder<User>
     */
    public function eligibleUsers(): Builder
    {
        return User::query()
            ->active()
            ->where(fn (Builder $users) => $users
                ->where('is_admin', true)
                ->orWhereHas('projects', fn (Builder $projects) => $projects->whereKey($this->getKey()))
                ->orWhereHas('teams.projects', fn (Builder $projects) => $projects->whereKey($this->getKey()))
            );
    }

    /**
     * @return HasMany<Automation, $this>
     */
    public function automations(): HasMany
    {
        return $this->hasMany(Automation::class);
    }

    /**
     * @return HasMany<CustomField, $this>
     */
    public function customFields(): HasMany
    {
        return $this->hasMany(CustomField::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<TaskStatus, $this>
     */
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

    /**
     * @return HasMany<SavedFilter, $this>
     */
    public function savedFilters(): HasMany
    {
        return $this->hasMany(SavedFilter::class);
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * The tag with this name, whatever its upper and lower case ("bug" finds "Bug"). Compared in PHP,
     * because SQLite's lower() only knows ASCII letters.
     */
    public function findTag(string $name, ?int $exceptId = null): ?Tag
    {
        $name = mb_strtolower(trim($name));

        return $this->tags()
            ->when($exceptId !== null, fn ($tags) => $tags->whereKeyNot($exceptId))
            ->get()
            ->first(fn (Tag $tag) => mb_strtolower($tag->name) === $name);
    }

    /**
     * The existing tag with this name or a new one in the next free color.
     */
    public function findOrCreateTag(string $name): Tag
    {
        return $this->findTag($name) ?? $this->tags()->create([
            'name' => trim($name),
            'color' => Color::next($this->tags()->count()),
        ]);
    }

    /**
     * Done and total counts of all descendants for those of the given tasks that have subtasks.
     * Reads only their subtrees, one query per level, not the whole project.
     *
     * @param  list<int>  $taskIds
     * @return array<int, array{done: int, total: int}>
     */
    public function subtaskProgress(array $taskIds): array
    {
        $childrenByParent = collect();
        $frontier = $taskIds;

        while ($frontier !== []) {
            $level = collect();

            foreach (array_chunk($frontier, 500) as $parentIds) {
                $level = $level->concat($this->tasks()
                    ->whereIn('parent_id', $parentIds)
                    ->where('is_section', false)
                    ->with('status:id,is_done')
                    ->get(['id', 'parent_id', 'status_id']));
            }

            $childrenByParent = $childrenByParent->union($level->groupBy('parent_id'));
            $frontier = $level->pluck('id')->all();
        }

        $progress = [];

        $count = function (int $id) use (&$count, $childrenByParent): array {
            $done = 0;
            $total = 0;

            foreach ($childrenByParent->get($id, []) as $child) {
                [$childDone, $childTotal] = $count($child->id);
                $total += 1 + $childTotal;
                $done += ($child->isDone() ? 1 : 0) + $childDone;
            }

            return [$done, $total];
        };

        foreach ($taskIds as $id) {
            [$done, $total] = $count($id);

            if ($total > 0) {
                $progress[$id] = ['done' => $done, 'total' => $total];
            }
        }

        return $progress;
    }

    /**
     * Next free position among the top-level tasks.
     */
    public function nextRootPosition(): int
    {
        return Task::nextPositionIn($this->tasks()->topLevel());
    }

    /**
     * Create a top-level task at the end of the manual order, by the signed-in person.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createRootTask(array $attributes): Task
    {
        return $this->tasks()->create([
            ...$attributes,
            'creator_id' => auth()->id(),
            'position' => $this->nextRootPosition(),
        ]);
    }

    /**
     * Put a top-level task into the single manual order shared by list and board.
     *
     * @param  list<int>  $visibleIds  Ordered ids currently shown to the user, without the moved task.
     */
    public function placeRootTask(Task $task, array $visibleIds, int $position): void
    {
        DB::transaction(function () use ($task, $visibleIds, $position) {
            $currentPositions = $this->tasks()
                ->topLevel()
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('position', 'id')
                ->all();
            $orderedIds = array_values(array_diff(array_keys($currentPositions), [$task->getKey()]));

            $visibleIds = array_values(array_intersect($visibleIds, $orderedIds));
            $position = max(0, min($position, count($visibleIds)));

            $insertAt = match (true) {
                $visibleIds === [] => count($orderedIds),
                $position < count($visibleIds) => array_search($visibleIds[$position], $orderedIds, true),
                default => array_search(end($visibleIds), $orderedIds, true) + 1,
            };

            array_splice($orderedIds, $insertAt, 0, [$task->getKey()]);

            Task::writePositions($orderedIds, $currentPositions);
        });
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
