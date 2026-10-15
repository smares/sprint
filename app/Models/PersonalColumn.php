<?php

namespace App\Models;

use App\Concerns\HasPosition;
use Database\Factories\PersonalColumnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A column of a person's own board in "My tasks". It belongs to that person alone; which tasks stand in it is their choice
 * (see PersonalPlacement), whatever the project or status of the task.
 */
#[Fillable(['user_id', 'name', 'position'])]
class PersonalColumn extends Model
{
    /** @use HasFactory<PersonalColumnFactory> */
    use HasFactory;

    use HasPosition;

    /** How many columns one person can have. */
    public const int MAX_PER_USER = 20;

    public const int MAX_NAME_LENGTH = 60;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PersonalPlacement, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(PersonalPlacement::class);
    }

    /**
     * Put the task in this column at the given place (0 = first); wherever it stood before, it leaves that column.
     */
    public function place(Task $task, int $position): void
    {
        DB::transaction(function () use ($task, $position): void {
            $current = $this->placements()->where('task_id', '!=', $task->getKey())->orderBy('position')->orderBy('id')->pluck('position', 'task_id')->all();
            $orderedIds = array_keys($current);

            array_splice($orderedIds, max(0, min($position, count($orderedIds))), 0, [$task->getKey()]);

            PersonalPlacement::query()->updateOrCreate(
                ['user_id' => $this->user_id, 'task_id' => $task->getKey()],
                ['personal_column_id' => $this->getKey()],
            );

            foreach ($orderedIds as $index => $id) {
                if (($current[$id] ?? null) === null || (int) $current[$id] !== $index) {
                    PersonalPlacement::query()->where('user_id', $this->user_id)->where('task_id', $id)->update(['position' => $index]);
                }
            }
        });
    }

    /**
     * @return Builder<static>
     */
    protected function positionSiblings(): Builder
    {
        return static::query()->where('user_id', $this->user_id);
    }
}
