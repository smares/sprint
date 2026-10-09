<?php

namespace App\Models;

use App\Concerns\HasReactions;
use App\Notifications\TaskCommented;
use App\Notifications\UserMentioned;
use App\Services\MarkdownService;
use App\Services\RealtimeService;
use App\Services\TaskSearchService;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Notification;

#[Fillable(['task_id', 'user_id', 'automation_name', 'body'])]
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    use HasReactions;

    protected static function booted(): void
    {
        static::saved(fn (self $comment) => app(TaskSearchService::class)->index($comment->task_id));
        static::deleted(fn (self $comment) => app(TaskSearchService::class)->index($comment->task_id));

        static::saved(fn (self $comment) => app(RealtimeService::class)->taskContentChanged($comment->task_id, 'comment'));
        static::deleted(fn (self $comment) => app(RealtimeService::class)->taskContentChanged($comment->task_id, 'comment'));

        static::updated(function (self $comment) {
            if (! $comment->wasChanged('body')) {
                return;
            }

            $comment->loadMissing('task', 'user');

            $added = array_diff(
                MarkdownService::mentionedUserIds($comment->body),
                MarkdownService::mentionedUserIds($comment->getOriginal('body')),
            );

            Notification::send(
                $comment->task->usersToMention($added, $comment->user),
                new UserMentioned($comment->task, 'comment', $comment->body, $comment->authorName()),
            );
        });

        static::created(function (self $comment) {
            $comment->loadMissing('task', 'user');

            $mentionedIds = MarkdownService::mentionedUserIds($comment->body);

            Notification::send(
                $comment->task->usersToNotify($comment->user ?? auth()->user())->reject(fn (User $user) => in_array($user->id, $mentionedIds, true)),
                new TaskCommented($comment),
            );

            Notification::send(
                $comment->task->usersToMention($mentionedIds, $comment->user ?? auth()->user()),
                new UserMentioned($comment->task, 'comment', $comment->body, $comment->authorName()),
            );
        });
    }

    /**
     * Whether the comment was changed after it was written.
     */
    public function wasEdited(): bool
    {
        return $this->updated_at !== null && $this->updated_at->gt($this->created_at->copy()->addSeconds(1));
    }

    public function reactionOwner(): ?User
    {
        // Comments written by an automation belong to nobody
        if ($this->user_id === null) {
            return null;
        }

        return User::query()->find($this->user_id);
    }

    public function reactionTask(): Task
    {
        return $this->relationLoaded('task') ? $this->task : Task::query()->findOrFail($this->task_id);
    }

    /**
     * The author's name; for a comment written by an automation, the automation's name.
     */
    public function authorName(): ?string
    {
        return $this->user->name ?? ($this->automation_name === null ? null : Automation::labelFor($this->automation_name));
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
}
