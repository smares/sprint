<?php

namespace App\Models;

use App\Notifications\TaskCommented;
use App\Notifications\UserMentioned;
use App\Services\Markdown;
use App\Services\TaskSearch;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Notification;

#[Fillable(['task_id', 'user_id', 'body'])]
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn (self $comment) => app(TaskSearch::class)->index($comment->task_id));
        static::deleted(fn (self $comment) => app(TaskSearch::class)->index($comment->task_id));

        static::updated(function (self $comment) {
            if (! $comment->wasChanged('body')) {
                return;
            }

            $comment->loadMissing('task', 'user');

            $added = array_diff(
                Markdown::mentionedUserIds($comment->body),
                Markdown::mentionedUserIds($comment->getOriginal('body')),
            );

            Notification::send(
                $comment->task->usersToMention($added, $comment->user),
                new UserMentioned($comment->task, 'comment', $comment->body, $comment->user?->name),
            );
        });

        static::created(function (self $comment) {
            $comment->loadMissing('task', 'user');

            $mentionedIds = Markdown::mentionedUserIds($comment->body);

            Notification::send(
                $comment->task->usersToNotify($comment->user)->reject(fn (User $user) => in_array($user->id, $mentionedIds, true)),
                new TaskCommented($comment),
            );

            Notification::send(
                $comment->task->usersToMention($mentionedIds, $comment->user),
                new UserMentioned($comment->task, 'comment', $comment->body, $comment->user?->name),
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

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
