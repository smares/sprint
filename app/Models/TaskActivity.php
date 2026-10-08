<?php

namespace App\Models;

use Database\Factories\TaskActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'user_id', 'type', 'data'])]
class TaskActivity extends Model
{
    /** @use HasFactory<TaskActivityFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What happened, as a sentence that continues after the person's name.
     */
    public function sentence(): string
    {
        $data = $this->data ?? [];
        $from = $data['from'] ?? '–';
        $to = $data['to'] ?? '–';
        $names = implode(', ', $data['names'] ?? []);

        return match ($this->type) {
            'created' => __('created the task'),
            'status_changed' => __('changed the status from “:from” to “:to”', ['from' => $from, 'to' => $to]),
            'assignee_changed' => __('changed the assignee from :from to :to', ['from' => $from, 'to' => $to]),
            'due_date_changed' => __('changed the due date from :from to :to', ['from' => $from, 'to' => $to]),
            'start_date_changed' => __('changed the start date from :from to :to', ['from' => $from, 'to' => $to]),
            'recurrence_changed' => ($data['to'] ?? '–') === '–' ? __('removed the repetition') : __('set the repetition to “:to”', ['to' => $to]),
            'recurrence_created' => __('created the next repetition for :to', ['to' => $to]),
            'attachments_added' => __('attached :names', ['names' => $names]),
            'attachments_removed' => __('removed the attachment :names', ['names' => $names]),
            'duplicated' => __('duplicated the task'),
            'recurrence_ended' => __('ended the repetition (end date reached)'),
            'title_changed' => __('changed the title from “:from” to “:to”', ['from' => $from, 'to' => $to]),
            'description_changed' => __('changed the description'),
            'parent_changed' => ($data['to'] ?? null) === null
                ? __('detached the task from “:from”', ['from' => $from])
                : __('moved the task under “:to”', ['to' => $to]),
            'tags_added' => __('added the tags :names', ['names' => $names]),
            'tags_removed' => __('removed the tags :names', ['names' => $names]),
            'collaborators_added' => __('added :names as collaborators', ['names' => $names]),
            'collaborators_removed' => __('removed :names as collaborators', ['names' => $names]),
            'blockers_added' => __('added :names as blockers', ['names' => $names]),
            'blockers_removed' => __('removed :names as blockers', ['names' => $names]),
            'blocking_added' => __('now blocks :names', ['names' => $names]),
            'field_changed' => __('changed :field from “:from” to “:to”', ['field' => $data['name'] ?? __('a field'), 'from' => $from, 'to' => $to]),
            'blocking_removed' => __('no longer blocks :names', ['names' => $names]),
            default => $this->type,
        };
    }
}
