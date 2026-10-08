<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'custom_field_id', 'option_id', 'value'])]
class CustomFieldValue extends Model
{
    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<CustomField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    /**
     * @return BelongsTo<CustomFieldOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(CustomFieldOption::class, 'option_id');
    }
}
