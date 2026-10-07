<?php

namespace App\Models;

use App\Markdown;
use App\Notifications\TaskCommented;
use App\Notifications\UserMentioned;
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

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
