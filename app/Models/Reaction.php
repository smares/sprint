<?php

namespace App\Models;

use Database\Factories\ReactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A person's emoji on a task or a comment; there is at most one per person and target (unique index).
 */
#[Fillable(['user_id', 'reactable_type', 'reactable_id', 'emoji'])]
class Reaction extends Model
{
    /** @use HasFactory<ReactionFactory> */
    use HasFactory;

    /**
     * Removes the reactions on these tasks and on their comments. The database removes subtasks and
     * comments by itself (foreign keys), but reactions point at them without one, so they would stay behind.
     *
     * @param  list<int>  $taskIds
     */
    public static function deleteForTasks(array $taskIds): void
    {
        foreach (array_chunk($taskIds, 500) as $chunk) {
            self::query()->where('reactable_type', (new Task)->getMorphClass())->whereIn('reactable_id', $chunk)->delete();
            self::query()->where('reactable_type', (new Comment)->getMorphClass())
                ->whereIn('reactable_id', Comment::query()->whereIn('task_id', $chunk)->select('id'))
                ->delete();
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reactable(): MorphTo
    {
        return $this->morphTo();
    }
}
