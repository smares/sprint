<?php

namespace App\Models;

use App\Concerns\HasPosition;
use App\Concerns\HasReactions;
use App\Enums\ActivityType;
use App\Enums\RepeatMode;
use App\Enums\RepeatUnit;
use App\Notifications\TasksStatusChanged;
use App\Notifications\TaskStatusChanged;
use App\Notifications\UserMentioned;
use App\Services\AutomationService;
use App\Services\CelebrationService;
use App\Services\MarkdownService;
use App\Services\RealtimeService;
use App\Services\TaskSearchService;
use Carbon\CarbonInterface;
use Closure;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['project_id', 'parent_id', 'is_section', 'assignee_id', 'creator_id', 'title', 'description', 'status_id', 'position', 'start_date', 'due_date', 'repeat_unit', 'repeat_interval', 'repeat_mode', 'repeat_until'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use HasPosition;
    use HasReactions;

    /** How far a task can be moved or stretched in one go (calendar and timeline), about ten years. */
    public const MAX_SHIFT_DAYS = 3650;

    /** How many intervals an overdue repeating task may skip to reach today; a guard against endless loops. */
    private const int MAX_CATCH_UP_STEPS = 1000;

    /**
     * While tasks are changed in bulk: status changes per recipient, to be sent as one message.
     *
     * @var array<int, array{user: User, changes: list<array{task: Task, from: string, to: string}>}>|null
     */
    private static ?array $bundledStatusChanges = null;

    /**
     * Change many tasks without one mail per task: everybody who would have got several messages about
     * status changes gets a single one that lists them; a single change is sent as usual.
     */
    public static function bundlingStatusNotifications(Closure $changes): void
    {
        self::$bundledStatusChanges = [];

        try {
            app(RealtimeService::class)->bundling($changes);
            $bundled = self::$bundledStatusChanges;
        } finally {
            self::$bundledStatusChanges = null;
        }

        $actorName = auth()->user()?->name;

        foreach ($bundled as $entry) {
            if (count($entry['changes']) === 1) {
                ['task' => $task, 'from' => $from, 'to' => $to] = $entry['changes'][0];

                Notification::send($entry['user'], new TaskStatusChanged($task, $from, $to, $actorName));

                continue;
            }

            Notification::send($entry['user'], new TasksStatusChanged(
                array_map(fn (array $change) => ['id' => $change['task']->id, 'title' => $change['task']->title, 'project' => $change['task']->project->name, 'from' => $change['from'], 'to' => $change['to']], $entry['changes']),
                $actorName,
            ));
        }
    }

    protected static function booted(): void
    {
        static::creating(function (self $task) {
            $task->status_id ??= $task->project->defaultStatus()->id;
        });

        static::created(function (self $task) {
            if (! $task->is_section) {
                $task->logActivity(ActivityType::Created);
            }
        });

        static::saved(function (self $task) {
            if ($task->wasRecentlyCreated || $task->wasChanged(['title', 'description'])) {
                app(TaskSearchService::class)->index($task);
            }
        });

        static::saved(fn (self $task) => app(RealtimeService::class)->taskChanged($task->project_id, $task->id, 'task'));

        static::deleted(fn (self $task) => app(RealtimeService::class)->taskChanged($task->project_id, $task->id, 'task'));

        static::deleting(function (self $task) {
            app(TaskSearchService::class)->forget($task->id);
            $task->deleteAttachmentFiles();
        });

        static::updated(function (self $task) {
            if ($task->is_section) {
                return;
            }

            $actor = auth()->user();
            $automation = app(AutomationService::class)->running();
            $actorName = $automation?->label() ?? $actor?->name;

            // Looked up once: the history, the celebration and the notifications all need the old status
            $old = null;

            if ($task->wasChanged('status_id')) {
                $task->setRelation('status', $task->statusById($task->status_id));
                $old = $task->statusById($task->getOriginal('status_id'));
            }

            $task->logChanges($old);

            if ($task->wasChanged('status_id') && $task->isDone() && ! $old?->is_done) {
                app(CelebrationService::class)->taskCompleted();
            }

            if ($task->wasChanged('status_id')) {
                foreach ($task->usersToNotify($actor) as $recipient) {
                    $change = ['task' => $task, 'from' => $old?->name ?? '–', 'to' => $task->status->name];

                    if (self::$bundledStatusChanges === null || $automation !== null) {
                        Notification::send($recipient, new TaskStatusChanged($task, $change['from'], $change['to'], $actorName));
                    } else {
                        self::$bundledStatusChanges[$recipient->id]['user'] = $recipient;
                        self::$bundledStatusChanges[$recipient->id]['changes'][] = $change;
                    }
                }
            }

            if ($task->wasChanged('status_id') && $task->isDone() && $task->repeat_unit !== null) {
                $task->spawnNextOccurrence();
            }

            if ($task->wasChanged('description')) {
                $added = array_diff(
                    MarkdownService::mentionedUserIds($task->description),
                    MarkdownService::mentionedUserIds($task->getOriginal('description')),
                );

                $task->notifyMentionedInDescription($added, $actor);
            }

            app(AutomationService::class)->flush();
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

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<TaskStatus, $this>
     */
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
     * The repeat rule in words, e.g. "every 2 weeks (after completion) until 2026-12-31".
     */
    public function recurrenceLabel(): ?string
    {
        if ($this->repeat_unit === null) {
            return null;
        }

        $interval = max(1, (int) $this->repeat_interval);

        $every = $interval === 1
            ? match ($this->repeat_unit) {
                RepeatUnit::Day => __('daily'),
                RepeatUnit::Week => __('weekly'),
                RepeatUnit::Month => __('monthly'),
                RepeatUnit::Year => __('yearly'),
            }
        : match ($this->repeat_unit) {
            RepeatUnit::Day => __('every :interval days', ['interval' => $interval]),
            RepeatUnit::Week => __('every :interval weeks', ['interval' => $interval]),
            RepeatUnit::Month => __('every :interval months', ['interval' => $interval]),
            RepeatUnit::Year => __('every :interval years', ['interval' => $interval]),
        };

        return $every
            .($this->repeat_mode === RepeatMode::Completion ? ' ('.__('after completion').')' : '')
            .($this->repeat_until ? ' '.__('until :date', ['date' => $this->repeat_until->isoFormat('L')]) : '');
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
        $today = today();
        $next = $this->repeat_mode === RepeatMode::Completion
            ? $unit->addTo($today, $interval)
            : $unit->addTo($due->copy()->startOfDay(), $interval);

        for ($guard = 0; $this->repeat_mode === RepeatMode::Schedule && $next < $today && $guard < self::MAX_CATCH_UP_STEPS; $guard++) {
            $next = $unit->addTo($next, $interval);
        }

        $this->updateQuietly(['repeat_unit' => null]);

        if ($this->repeat_until !== null && $next->startOfDay() > $this->repeat_until->copy()->startOfDay()) {
            $this->logActivity(ActivityType::RecurrenceEnded);

            return null;
        }

        $offset = (int) $due->copy()->startOfDay()->diffInDays($next, false);

        $copy = $this->makeCopy([
            'due_date' => $next,
            'start_date' => $this->start_date?->copy()->addDays($offset),
            'repeat_unit' => $unit,
            'repeat_interval' => $this->repeat_interval,
            'repeat_mode' => $this->repeat_mode,
            'repeat_until' => $this->repeat_until,
        ], $offset);

        $this->logActivity(ActivityType::RecurrenceCreated, ['to' => $next->toDateString()]);

        return $copy;
    }

    /**
     * Copy this task with its subtasks, tags, collaborators and field values, open again and without the
     * recurrence rule. Comments, attachments and dependencies stay with the original.
     */
    public function duplicate(): self
    {
        // The copy is marked in the language of the person duplicating it; the title stays within 255 characters.
        $room = 255 - mb_strlen(__(':title (copy)', ['title' => '']));
        $copy = $this->makeCopy([
            'title' => __(':title (copy)', ['title' => Str::limit($this->title, $room, '')]),
        ]);

        $this->logActivity(ActivityType::Duplicated);

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $attributes  What differs from the original.
     * @param  int  $offset  Days by which the dates of the subtasks move.
     */
    private function makeCopy(array $attributes, int $offset = 0): self
    {
        return DB::transaction(function () use ($attributes, $offset) {
            $copy = self::create($attributes + [
                'project_id' => $this->project_id,
                'parent_id' => $this->parent_id,
                'assignee_id' => $this->assignee_id,
                'creator_id' => auth()->id() ?? $this->creator_id,
                'title' => $this->title,
                'description' => $this->description,
                'status_id' => $this->project->defaultStatus()->id,
                'position' => $this->position ?? 0,
                'due_date' => $this->due_date,
                'start_date' => $this->start_date,
            ]);

            $copy->tags()->sync($this->tags()->pluck('tags.id')->all());
            $copy->collaborators()->sync($this->collaborators()->pluck('users.id')->all());

            foreach ($this->fieldValues()->get() as $value) {
                $copy->fieldValues()->create($value->only(['custom_field_id', 'option_id', 'value']));
            }

            $this->copyChildrenTo($copy, $offset);

            return $copy;
        });
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

    /**
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_collaborators');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Remove the stored files of this task and all its subtasks; the rows go with the task itself.
     */
    private function deleteAttachmentFiles(): void
    {
        foreach (array_chunk([$this->getKey(), ...$this->descendantIds()], 500) as $chunk) {
            Storage::disk()->delete(Attachment::whereIn('task_id', $chunk)->pluck('path')->all());
        }
    }

    /**
     * Ids of all subtasks at every depth, one query per level.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $frontier = [$this->getKey()];

        while ($frontier !== []) {
            $frontier = self::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /**
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Store an uploaded file on the default disk and attach it, recording who uploaded it.
     */
    public function attachUpload(UploadedFile $file): Attachment
    {
        return $this->attachments()->create([
            'user_id' => auth()->id(),
            'name' => $file->getClientOriginalName(),
            'path' => $file->store("attachments/{$this->project_id}/{$this->getKey()}"),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Tasks that must be finished before this one.
     *
     * @return BelongsToMany<Task, $this, Pivot>
     */
    public function blockers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'blocked_id', 'blocker_id');
    }

    /**
     * Tasks that are waiting for this one.
     *
     * @return BelongsToMany<Task, $this, Pivot>
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
            $child->update(['parent_id' => $this->getKey()]);
            $child->moveTo($position);
        });
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function notificationMutes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_notification_mutes');
    }

    /**
     * A status of this task's project, taken from the loaded statuses where possible (bulk changes load them once).
     */
    private function statusById(mixed $id): ?TaskStatus
    {
        if ($id === null) {
            return null;
        }

        if ($this->relationLoaded('project') && $this->project->relationLoaded('statuses')) {
            return $this->project->statuses->firstWhere('id', (int) $id) ?? TaskStatus::find($id);
        }

        return TaskStatus::find($id);
    }

    /**
     * A person, taken from the loaded assignee or collaborators where possible.
     */
    private function userById(int $id): ?User
    {
        $loaded = collect([
            $this->relationLoaded('assignee') ? $this->assignee : null,
            ...($this->relationLoaded('collaborators') ? $this->collaborators : []),
        ])->filter()->firstWhere('id', $id);

        return $loaded ?? User::find($id);
    }

    public function reactionOwner(): ?User
    {
        return User::query()->find($this->creator_id);
    }

    public function reactionTask(): self
    {
        return $this;
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
        $muted = $this->relationLoaded('notificationMutes') ? $this->notificationMutes->pluck('id') : $this->notificationMutes()->pluck('users.id');
        $collaborators = $this->relationLoaded('collaborators') ? $this->collaborators : $this->collaborators()->get();
        $assignee = $this->assignee_id === null ? null : $this->userById($this->assignee_id);

        $candidates = collect([$assignee, ...$collaborators])
            ->filter()
            ->unique('id')
            ->reject(fn (User $user) => $muted->contains($user->id) || $user->id === $except?->id)
            ->filter(fn (User $user) => $user->isActive());
        $viewers = $this->project->viewerIds($candidates);

        return $candidates->filter(fn (User $user) => in_array($user->id, $viewers, true))->values();
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

        $candidates = User::whereIn('id', collect($userIds)->all())->active()->get()
            ->reject(fn (User $user) => $muted->contains($user->id) || $user->id === $except?->id);
        $viewers = $this->project->viewerIds($candidates);

        return $candidates->filter(fn (User $user) => in_array($user->id, $viewers, true))->values();
    }

    /**
     * Tells the given people (those who may be told, see usersToMention) that the description mentions them.
     *
     * @param  iterable<int>  $userIds
     */
    public function notifyMentionedInDescription(iterable $userIds, ?User $actor): void
    {
        if (collect($userIds)->isEmpty()) {
            return;
        }

        Notification::send(
            $this->usersToMention($userIds, $actor),
            new UserMentioned($this, 'description', (string) $this->description, $actor?->name),
        );
    }

    /**
     * @return HasMany<CustomFieldValue, $this>
     */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /**
     * @return HasMany<TaskActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function logActivity(ActivityType $type, array $data = []): void
    {
        $automation = app(AutomationService::class)->running();

        if ($automation !== null) {
            $data += ['automation' => $automation->name, 'by' => auth()->user()?->name];
        }

        $this->activities()->create([
            'user_id' => $automation === null ? auth()->id() : null,
            'automation_id' => $automation?->id,
            'type' => $type,
            'data' => $data === [] ? null : $data,
        ]);

        app(AutomationService::class)->record($this, $type, $data);
    }

    /**
     * Record what a sync of tags, collaborators or dependencies added and removed, with the names.
     *
     * @param  array{attached?: array<int, int|string>, detached?: array<int, int|string>}  $changes
     * @param  Closure(list<int>): list<string>  $names  The names for the given ids, sorted.
     */
    public function logSyncChanges(ActivityType $added, ActivityType $removed, array $changes, Closure $names): void
    {
        foreach ([[$added, 'attached'], [$removed, 'detached']] as [$type, $key]) {
            if (($changes[$key] ?? []) !== []) {
                $ids = array_values(array_map(intval(...), $changes[$key]));

                $this->logActivity($type, ['names' => $names($ids), 'ids' => $ids]);
            }
        }

        app(AutomationService::class)->flush();
    }

    /**
     * Store, change or (with null) remove the value of a custom field and record the change.
     *
     * @param  array{option_id: ?int, value: ?string}|null  $attributes
     */
    public function setFieldValue(CustomField $field, ?array $attributes): void
    {
        $current = $this->fieldValues()->where('custom_field_id', $field->id)->first();
        $old = $current?->stored();
        $new = $attributes === null ? null : ($attributes['option_id'] ?? $attributes['value']);

        if ((string) $old === (string) $new) {
            return;
        }

        if ($attributes === null) {
            $current?->delete();
        } else {
            $this->fieldValues()->updateOrCreate(['custom_field_id' => $field->id], $attributes);
        }

        $this->logActivity(ActivityType::FieldChanged, [
            'name' => $field->name,
            'from' => $field->text($old) ?? '–',
            'to' => $field->text($new) ?? '–',
        ]);
    }

    /**
     * Record what the last save changed on the task itself.
     */
    private function logChanges(?TaskStatus $oldStatus): void
    {
        $date = fn (mixed $value) => $value === null ? '–' : Carbon::parse($value)->toDateString();
        $userName = fn (mixed $id) => $id === null ? '–' : ($this->userById((int) $id)?->name ?? '–');

        if ($this->wasChanged('status_id')) {
            $this->logActivity(ActivityType::StatusChanged, [
                'from' => $oldStatus?->name ?? '–',
                'to' => $this->loadMissing('status')->status->name,
            ]);
        }

        if ($this->wasChanged('assignee_id')) {
            $this->logActivity(ActivityType::AssigneeChanged, [
                'from' => $userName($this->getOriginal('assignee_id')),
                'to' => $userName($this->assignee_id),
            ]);
        }

        if ($this->wasChanged('due_date')) {
            $this->logActivity(ActivityType::DueDateChanged, [
                'from' => $date($this->getOriginal('due_date')),
                'to' => $date($this->due_date),
            ]);
        }

        if ($this->wasChanged('start_date')) {
            $this->logActivity(ActivityType::StartDateChanged, [
                'from' => $date($this->getOriginal('start_date')),
                'to' => $date($this->start_date),
            ]);
        }

        if ($this->wasChanged(['repeat_unit', 'repeat_interval', 'repeat_mode', 'repeat_until'])) {
            $this->logActivity(ActivityType::RecurrenceChanged, ['to' => $this->recurrenceLabel() ?? '–']);
        }

        if ($this->wasChanged('title')) {
            $this->logActivity(ActivityType::TitleChanged, ['from' => $this->getOriginal('title'), 'to' => $this->title]);
        }

        if ($this->wasChanged('description')) {
            $this->logActivity(ActivityType::DescriptionChanged);
        }

        if ($this->wasChanged('parent_id')) {
            $this->logActivity(ActivityType::ParentChanged, [
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

        return $this->blockers()->open()->exists();
    }

    /**
     * Whether following the "blocking" edges leads back to this task.
     */
    public function hasDependencyCycle(): bool
    {
        $visited = [];
        $frontier = $this->blocking()->pluck('tasks.id')->all();

        while ($frontier !== []) {
            if (in_array($this->getKey(), $frontier, true)) {
                return true;
            }

            $visited = [...$visited, ...$frontier];

            $frontier = DB::table('task_dependencies')
                ->whereIn('blocker_id', $frontier)
                ->pluck('blocked_id')
                ->unique()
                ->diff($visited)
                ->values()
                ->all();
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
    public function spanStart(): ?CarbonInterface
    {
        return $this->start_date ?? $this->due_date;
    }

    /**
     * Last day of the task's time span.
     */
    public function spanEnd(): ?CarbonInterface
    {
        return $this->due_date ?? $this->start_date;
    }

    /**
     * Tasks whose time span touches the given days.
     *
     * @param  Builder<static>  $query
     */
    protected function scopeOverlapping(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->whereRaw('coalesce(tasks.start_date, tasks.due_date) <= ?', [$to->copy()->endOfDay()->toDateTimeString()])
            ->whereRaw('coalesce(tasks.due_date, tasks.start_date) >= ?', [$from->copy()->startOfDay()->toDateTimeString()]);
    }

    /**
     * Tasks in projects the person may open. The projects are found once by a plain subquery
     * instead of a check per task.
     *
     * @param  Builder<static>  $query
     */
    protected function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->is_admin) {
            $query->whereIn('tasks.project_id', Project::query()->visibleTo($user)->select('projects.id'));
        }
    }

    /**
     * Tasks that are not subtasks.
     *
     * @param  Builder<static>  $query
     */
    protected function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('tasks.parent_id');
    }

    /**
     * Tasks whose status is not a done status.
     *
     * @param  Builder<static>  $query
     */
    protected function scopeOpen(Builder $query): void
    {
        $query->whereIn('tasks.status_id', TaskStatus::query()->where('is_done', false)->select('id'));
    }

    /**
     * Tasks whose status is a done status.
     *
     * @param  Builder<static>  $query
     */
    protected function scopeDone(Builder $query): void
    {
        $query->whereIn('tasks.status_id', TaskStatus::query()->where('is_done', true)->select('id'));
    }

    /**
     * Tasks the person is assigned to or collaborates on.
     *
     * @param  Builder<static>  $query
     */
    protected function scopeInvolving(Builder $query, User $user): void
    {
        $query->where(fn (Builder $tasks) => $tasks
            ->where('tasks.assignee_id', $user->getKey())
            ->orWhereIn('tasks.id', DB::table('task_collaborators')->where('user_id', $user->getKey())->select('task_id'))
        );
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && ! $this->isDone()
            && $this->due_date->isPast()
            && ! $this->due_date->isToday();
    }

    /**
     * Subtasks share one order per parent, top-level tasks one per project.
     *
     * @return Builder<static>
     */
    protected function positionSiblings(): Builder
    {
        return static::query()->where('project_id', $this->project_id)->where('parent_id', $this->parent_id);
    }
}
