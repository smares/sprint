<?php

namespace App\Models;

use App\Casts\ColorCast;
use Database\Factories\CustomFieldOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['custom_field_id', 'name', 'color', 'position'])]
class CustomFieldOption extends Model
{
    /** @use HasFactory<CustomFieldOptionFactory> */
    use HasFactory;

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
}
