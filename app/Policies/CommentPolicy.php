<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CommentPolicy
{
    /**
     * Only the author changes the words, and only while allowed to edit the project.
     */
    public function update(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id && Gate::forUser($user)->allows('edit', $comment->task->project);
    }

    /**
     * The author (while allowed to edit) and the project's managers may delete a comment.
     */
    public function delete(User $user, Comment $comment): bool
    {
        return $this->update($user, $comment) || Gate::forUser($user)->allows('manage', $comment->task->project);
    }
}
