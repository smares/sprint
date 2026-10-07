<?php

namespace App\Models;

use App\Notifications\TaskCommented;
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

            Notification::send(
                $comment->task->usersToNotify($comment->user),
                new TaskCommented($comment),
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
