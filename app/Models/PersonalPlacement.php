<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The place a person gave a task on their own board: the column and the position in it.
 */
#[Fillable(['user_id', 'task_id', 'personal_column_id', 'position'])]
class PersonalPlacement extends Model
{
    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<PersonalColumn, $this>
     */
    public function column(): BelongsTo
    {
        return $this->belongsTo(PersonalColumn::class, 'personal_column_id');
    }
}
