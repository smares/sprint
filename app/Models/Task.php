<?php

namespace App\Models;

use App\Markdown;
use App\Notifications\TaskStatusChanged;
use App\Notifications\UserMentioned;
use App\RepeatMode;
use App\RepeatUnit;
use App\TaskSearch;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

#[Fillable(['project_id', 'parent_id', 'is_section', 'assignee_id', 'creator_id', 'title', 'description', 'status_id', 'position', 'start_date', 'due_date', 'repeat_unit', 'repeat_interval', 'repeat_mode', 'repeat_until'])]
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

        static::saved(function (self $task) {
            if ($task->wasRecentlyCreated || $task->wasChanged(['title', 'description'])) {
                app(TaskSearch::class)->index($task);
            }
        });

        static::deleting(function (self $task) {
            app(TaskSearch::class)->forget($task->id);
            $task->deleteAttachmentFiles();
        });

        static::updated(function (self $task) {
            if ($task->is_section) {
                return;
            }

            $actor = auth()->user();

            if ($task->wasChanged('status_id')) {
                $task->unsetRelation('status');
            }

            $task->logChanges();

            if ($task->wasChanged('status_id')) {
                $old = TaskStatus::find($task->getOriginal('status_id'));

                Notification::send(
                    $task->usersToNotify($actor),
                    new TaskStatusChanged($task, $old?->name ?? '–', $task->status->name, $actor?->name),
                );
            }

            if ($task->wasChanged('status_id') && $task->isDone() && $task->repeat_unit !== null) {
                $task->spawnNextOccurrence();
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
            'start_date' => 'date',
            'repeat_unit' => RepeatUnit::class,
            'repeat_mode' => RepeatMode::class,
            'repeat_until' => 'date',
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

    public function isRecurring(): bool
    {
        return $this->repeat_unit !== null;
    }

    /**
     * The repeat rule in words, e.g. "alle 2 Wochen (nach Erledigung) bis 31.12.2026".
     */
    public function recurrenceLabel(): ?string
    {
        if ($this->repeat_unit === null) {
            return null;
        }

        $interval = max(1, (int) $this->repeat_interval);

        $every = $interval === 1
            ? match ($this->repeat_unit) {
                RepeatUnit::Day => 'täglich',
                RepeatUnit::Week => 'wöchentlich',
                RepeatUnit::Month => 'monatlich',
                RepeatUnit::Year => 'jährlich',
            }
        : "alle {$interval} ".$this->repeat_unit->label();

        return $every
            .($this->repeat_mode === RepeatMode::Completion ? ' (nach Erledigung)' : '')
            .($this->repeat_until ? ' bis '.$this->repeat_until->format('d.m.Y') : '');
    }

    /**
     * Create the next task of a repeating series once this one is finished. The rule moves on to the new
     * task, so finishing, reopening and finishing this one again does not create a second copy.
     */
    public function spawnNextOccurrence(): ?self
    {
        $unit = $this->repeat_unit;
        $due = $this->due_date;

        if ($unit === null || $due === null || $this->is_section) {
            return null;
        }

        $interval = max(1, (int) $this->repeat_interval);
        $today = now()->startOfDay();
        $next = $this->repeat_mode === RepeatMode::Completion
            ? $unit->addTo($today, $interval)
            : $unit->addTo($due->copy()->startOfDay(), $interval);

        for ($guard = 0; $this->repeat_mode === RepeatMode::Schedule && $next < $today && $guard < 1000; $guard++) {
            $next = $unit->addTo($next, $interval);
        }

        $this->updateQuietly(['repeat_unit' => null]);

        if ($this->repeat_until !== null && $next->startOfDay() > $this->repeat_until->copy()->startOfDay()) {
            $this->logActivity('recurrence_ended');

            return null;
        }

        $offset = (int) $due->copy()->startOfDay()->diffInDays($next, false);

        $copy = DB::transaction(function () use ($next, $offset, $unit) {
            $copy = self::create([
                'project_id' => $this->project_id,
                'parent_id' => $this->parent_id,
                'assignee_id' => $this->assignee_id,
                'creator_id' => auth()->id() ?? $this->creator_id,
                'title' => $this->title,
                'description' => $this->description,
                'status_id' => $this->project->defaultStatus()->id,
                'position' => $this->position ?? 0,
                'due_date' => $next,
                'start_date' => $this->start_date?->copy()->addDays($offset),
                'repeat_unit' => $unit,
                'repeat_interval' => $this->repeat_interval,
                'repeat_mode' => $this->repeat_mode,
                'repeat_until' => $this->repeat_until,
            ]);

            $copy->tags()->sync($this->tags()->pluck('tags.id')->all());
            $copy->collaborators()->sync($this->collaborators()->pluck('users.id')->all());

            foreach ($this->fieldValues()->get() as $value) {
                $copy->fieldValues()->create($value->only(['custom_field_id', 'option_id', 'value']));
            }

            $this->copyChildrenTo($copy, $offset);

            return $copy;
        });

        $this->logActivity('recurrence_created', ['to' => $next->format('d.m.Y')]);

        return $copy;
    }

    /**
     * Copy the subtasks and headings below this task (open again, dates moved along) below the new task.
     */
    private function copyChildrenTo(self $copy, int $offset): void
    {
        foreach ($this->children()->get() as $child) {
            $new = self::create([
                'project_id' => $child->project_id,
                'parent_id' => $copy->id,
                'is_section' => $child->is_section,
                'assignee_id' => $child->assignee_id,
                'creator_id' => $copy->creator_id,
                'title' => $child->title,
                'description' => $child->description,
                'position' => $child->position ?? 0,
                'due_date' => $child->due_date?->copy()->addDays($offset),
                'start_date' => $child->start_date?->copy()->addDays($offset),
            ]);

            $child->copyChildrenTo($new, $offset);
        }
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

    /**
     * Remove the stored files of this task and all its subtasks; the rows go with the task itself.
     */
    private function deleteAttachmentFiles(): void
    {
        $ids = [$this->getKey()];
        $frontier = $ids;

        while ($frontier !== []) {
            $frontier = self::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        Storage::disk(Attachment::DISK)->delete(Attachment::whereIn('task_id', $ids)->pluck('path')->all());
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
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
            ->filter(fn (User $user) => $user->isActive() && $this->project->canBeViewedBy($user))
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

        return User::whereIn('id', collect($userIds)->all())->active()->get()
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

        if ($this->wasChanged('start_date')) {
            $this->logActivity('start_date_changed', [
                'from' => $date($this->getOriginal('start_date')),
                'to' => $date($this->start_date),
            ]);
        }

        if ($this->wasChanged(['repeat_unit', 'repeat_interval', 'repeat_mode', 'repeat_until'])) {
            $this->logActivity('recurrence_changed', ['to' => $this->recurrenceLabel() ?? '–']);
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

    /**
     * Move the whole task in time: start and due date shift by the same number of days.
     */
    public function shiftDates(int $days): void
    {
        $this->update([
            'start_date' => $this->start_date?->copy()->addDays($days),
            'due_date' => $this->due_date?->copy()->addDays($days),
        ]);
    }

    /**
     * Stretch or shrink the task by dragging one edge; the edges never cross.
     *
     * @param  'start'|'end'  $edge
     */
    public function resizeSpan(string $edge, int $days): void
    {
        $start = $this->spanStart()?->copy();
        $end = $this->spanEnd()?->copy();

        if ($start === null || $end === null) {
            return;
        }

        if ($edge === 'start') {
            $start = $start->addDays($days);
            $start = $start->gt($end) ? $end->copy() : $start;
        } else {
            $end = $end->addDays($days);
            $end = $end->lt($start) ? $start->copy() : $end;
        }

        $this->update(['start_date' => $start, 'due_date' => $end]);
    }

    /**
     * First day of the task's time span; tasks with only a due date last a single day.
     */
    public function spanStart(): ?Carbon
    {
        return $this->start_date ?? $this->due_date;
    }

    /**
     * Last day of the task's time span.
     */
    public function spanEnd(): ?Carbon
    {
        return $this->due_date ?? $this->start_date;
    }

    /**
     * Tasks whose time span touches the given days.
     */
    public function scopeOverlapping(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->whereRaw('coalesce(tasks.start_date, tasks.due_date) <= ?', [$to->copy()->endOfDay()->toDateTimeString()])
            ->whereRaw('coalesce(tasks.due_date, tasks.start_date) >= ?', [$from->copy()->startOfDay()->toDateTimeString()]);
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && ! $this->isDone()
            && $this->due_date->isPast()
            && ! $this->due_date->isToday();
    }
}
