<?php

namespace App\Models;

use App\Notifications\TaskStatusChanged;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

#[Fillable(['project_id', 'parent_id', 'is_section', 'assignee_id', 'creator_id', 'title', 'description', 'status_id', 'position', 'due_date'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $task) {
            $task->status_id ??= $task->project->defaultStatus()->id;
        });

        static::updated(function (self $task) {
            if ($task->is_section || ! $task->wasChanged('status_id')) {
                return;
            }

            $old = TaskStatus::find($task->getOriginal('status_id'));
            $new = $task->status;
            $actor = auth()->user();

            Notification::send(
                $task->usersToNotify($actor),
                new TaskStatusChanged($task, $old?->name ?? '–', $new->name, $actor?->name),
            );
        });
    }

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'is_section' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
    }

    public function isDone(): bool
    {
        return (bool) $this->status?->is_done;
    }

    /**
     * Switch between the project's done status and its default open status.
     */
    public function toggleDone(): void
    {
        $this->update([
            'status_id' => $this->isDone() ? $this->project->defaultStatus()->id : $this->project->doneStatus()->id,
        ]);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_collaborators');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Tasks that must be finished before this one.
     */
    public function blockers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'blocked_id', 'blocker_id');
    }

    /**
     * Tasks that are waiting for this one.
     */
    public function blocking(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'blocker_id', 'blocked_id');
    }

    /**
     * Put the given task at the position among this task's children and make it one of them.
     */
    public function placeChild(self $child, int $position): void
    {
        DB::transaction(function () use ($child, $position) {
            $orderedIds = $this->children()->whereKeyNot($child->getKey())->pluck('id')->all();

            array_splice($orderedIds, max(0, min($position, count($orderedIds))), 0, [$child->getKey()]);

            $child->update(['parent_id' => $this->getKey()]);

            foreach ($orderedIds as $index => $id) {
                self::whereKey($id)->update(['position' => $index]);
            }
        });
    }

    public function notificationMutes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_notification_mutes');
    }

    public function isMutedBy(User $user): bool
    {
        return $this->notificationMutes()->whereKey($user->getKey())->exists();
    }

    public function setMutedBy(User $user, bool $muted): void
    {
        if ($muted) {
            $this->notificationMutes()->syncWithoutDetaching([$user->getKey()]);
        } else {
            $this->notificationMutes()->detach($user->getKey());
        }
    }

    /**
     * People who want to hear about this task: the assignee and all collaborators, without
     * those who muted it and without the person who caused the event.
     *
     * @return Collection<int, User>
     */
    public function usersToNotify(?User $except = null): Collection
    {
        $muted = $this->notificationMutes()->pluck('users.id');

        return collect([$this->assignee, ...$this->collaborators()->get()])
            ->filter()
            ->unique('id')
            ->reject(fn (User $user) => $muted->contains($user->id) || $user->id === $except?->id)
            ->values();
    }

    public function isBlocked(): bool
    {
        if ($this->relationLoaded('blockers')) {
            return $this->blockers->contains(fn (self $blocker) => ! $blocker->isDone());
        }

        return $this->blockers()->whereHas('status', fn ($status) => $status->where('is_done', false))->exists();
    }

    /**
     * Whether following the "blocking" edges leads back to this task.
     */
    public function hasDependencyCycle(): bool
    {
        $visited = [];
        $queue = $this->blocking()->pluck('tasks.id')->all();

        while ($queue !== []) {
            $id = array_shift($queue);

            if ($id === $this->getKey()) {
                return true;
            }

            if (isset($visited[$id])) {
                continue;
            }

            $visited[$id] = true;
            $queue = [...$queue, ...self::findOrFail($id)->blocking()->pluck('tasks.id')->all()];
        }

        return false;
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && ! $this->isDone()
            && $this->due_date->isPast()
            && ! $this->due_date->isToday();
    }
}
