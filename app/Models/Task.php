<?php

namespace App\Models;

use App\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'assignee_id', 'creator_id', 'title', 'description', 'status', 'position', 'due_date'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'due_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
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

    public function isBlocked(): bool
    {
        if ($this->relationLoaded('blockers')) {
            return $this->blockers->contains(fn (self $blocker) => $blocker->status !== TaskStatus::Done);
        }

        return $this->blockers()->where('status', '!=', TaskStatus::Done)->exists();
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
            && $this->status !== TaskStatus::Done
            && $this->due_date->isPast()
            && ! $this->due_date->isToday();
    }
}
