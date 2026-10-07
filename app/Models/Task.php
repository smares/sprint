<?php

namespace App\Models;

use App\Markdown;
use App\Notifications\TaskStatusChanged;
use App\Notifications\UserMentioned;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
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

        static::created(function (self $task) {
            if (! $task->is_section) {
                $task->logActivity('created');
            }
        });

        static::updated(function (self $task) {
            if ($task->is_section) {
                return;
            }

            $actor = auth()->user();
            $task->logChanges();

            if ($task->wasChanged('status_id')) {
                $old = TaskStatus::find($task->getOriginal('status_id'));

                Notification::send(
                    $task->usersToNotify($actor),
                    new TaskStatusChanged($task, $old?->name ?? '–', $task->status->name, $actor?->name),
                );
            }

            if ($task->wasChanged('description')) {
                $added = array_diff(
                    Markdown::mentionedUserIds($task->description),
                    Markdown::mentionedUserIds($task->getOriginal('description')),
                );

                Notification::send(
                    $task->usersToMention($added, $actor),
                    new UserMentioned($task, 'description', (string) $task->description, $actor?->name),
                );
            }
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
            ->filter(fn (User $user) => $this->project->canBeViewedBy($user))
            ->values();
    }

    /**
     * Mentioned people who should get an email: not the person who wrote the mention and not
     * those who muted this task. Mentioned people do not need to be assignee or collaborator.
     *
     * @param  iterable<int>  $userIds
     * @return Collection<int, User>
     */
    public function usersToMention(iterable $userIds, ?User $except = null): Collection
    {
        $muted = $this->notificationMutes()->pluck('users.id');

        return User::whereIn('id', collect($userIds)->all())->get()
            ->reject(fn (User $user) => $muted->contains($user->id) || $user->id === $except?->id)
            ->filter(fn (User $user) => $this->project->canBeViewedBy($user))
            ->values();
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function logActivity(string $type, array $data = []): void
    {
        $this->activities()->create([
            'user_id' => auth()->id(),
            'type' => $type,
            'data' => $data === [] ? null : $data,
        ]);
    }

    /**
     * Record what the last save changed on the task itself.
     */
    private function logChanges(): void
    {
        $date = fn (mixed $value) => $value === null ? '–' : Carbon::parse($value)->format('d.m.Y');
        $userName = fn (mixed $id) => $id === null ? '–' : (User::find($id)?->name ?? '–');

        if ($this->wasChanged('status_id')) {
            $this->logActivity('status_changed', [
                'from' => TaskStatus::find($this->getOriginal('status_id'))?->name ?? '–',
                'to' => $this->status->name,
            ]);
        }

        if ($this->wasChanged('assignee_id')) {
            $this->logActivity('assignee_changed', [
                'from' => $userName($this->getOriginal('assignee_id')),
                'to' => $userName($this->assignee_id),
            ]);
        }

        if ($this->wasChanged('due_date')) {
            $this->logActivity('due_date_changed', [
                'from' => $date($this->getOriginal('due_date')),
                'to' => $date($this->due_date),
            ]);
        }

        if ($this->wasChanged('title')) {
            $this->logActivity('title_changed', ['from' => $this->getOriginal('title'), 'to' => $this->title]);
        }

        if ($this->wasChanged('description')) {
            $this->logActivity('description_changed');
        }

        if ($this->wasChanged('parent_id')) {
            $this->logActivity('parent_changed', [
                'from' => self::find($this->getOriginal('parent_id'))?->title,
                'to' => $this->parent?->title,
            ]);
        }
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
