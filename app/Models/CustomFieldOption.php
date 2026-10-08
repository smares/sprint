<?php

namespace App\Models;

use App\Casts\ColorCast;
use App\Concerns\HasPosition;
use Database\Factories\CustomFieldOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['custom_field_id', 'name', 'color', 'position'])]
class CustomFieldOption extends Model
{
    /** @use HasFactory<CustomFieldOptionFactory> */
    use HasFactory;

    use HasPosition;

    protected function casts(): array
    {
        return ['color' => ColorCast::class];
    }

    /**
     * @return BelongsTo<CustomField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    /**
     * @return Builder<static>
     */
    protected function positionSiblings(): Builder
    {
        return static::query()->where('custom_field_id', $this->custom_field_id);
    }
}
