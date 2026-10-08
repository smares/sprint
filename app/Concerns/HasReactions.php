<?php

namespace App\Concerns;

use App\Models\Reaction;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ReactionReceived;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Reactions on a task or a comment: one emoji per person. The person the task or comment belongs to
 * gets an entry in the inbox (no mail) when someone else reacts.
 *
 * @property-read Collection<int, Reaction> $reactions
 */
trait HasReactions
{
    protected static function bootHasReactions(): void
    {
        // No return value: a listener that returns something stops the others (such as deleting the attachment files)
        static::deleting(function (self $model): void {
            $model->reactions()->delete();
        });
    }

    /**
     * @return MorphMany<Reaction, $this>
     */
    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactable');
    }

    /**
     * Who the task or comment belongs to: whoever created the task or wrote the comment.
     */
    abstract public function reactionOwner(): ?User;

    /**
     * The task the reaction is about (the task itself, or the comment's task).
     */
    abstract public function reactionTask(): Task;

    /**
     * React with the emoji (a single emoji, see Emoji::normalize): the same emoji again takes the reaction back, another one replaces the person's earlier one.
     * Returns the reaction that is there afterwards, or null when it was taken back.
     */
    public function toggleReaction(User $user, string $emoji): ?Reaction
    {
        $reaction = $this->reactions()->where('user_id', $user->id)->first();

        if ($reaction?->emoji === $emoji) {
            $reaction->delete();

            return null;
        }

        $reaction ??= new Reaction(['user_id' => $user->id]);
        $reaction->fill(['emoji' => $emoji]);
        $this->reactions()->save($reaction);

        $this->tellOwnerAbout($reaction, $user);

        return $reaction;
    }

    /**
     * An inbox entry for the owner, who is not told twice about the same person's reaction while it is unread:
     * changing the emoji updates the entry.
     */
    private function tellOwnerAbout(Reaction $reaction, User $reactor): void
    {
        $owner = $this->reactionOwner();
        $task = $this->reactionTask()->loadMissing('project');

        if ($owner === null || $owner->is($reactor) || ! $owner->isActive() || $task->isMutedBy($owner) || ! $task->project->canBeViewedBy($owner)) {
            return;
        }

        $target = $this instanceof Task ? 'task' : 'comment';

        /** @var DatabaseNotification|null $unread */
        $unread = $owner->unreadNotifications()
            ->where('type', ReactionReceived::class)
            ->where('data->reactor_id', $reactor->id)
            ->where('data->target', $target)
            ->where('data->target_id', $this->getKey())
            ->first();

        if ($unread !== null) {
            $unread->update(['data' => [...$unread->data, 'emoji' => $reaction->emoji]]);

            return;
        }

        $owner->notify(new ReactionReceived($task, $reactor, $target, (int) $this->getKey(), $reaction->emoji));
    }
}
