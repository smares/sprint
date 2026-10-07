<?php

namespace App\Models;

use App\CustomFieldType;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'name', 'type', 'position', 'show_in_list'])]
class CustomField extends Model
{
    /** @use HasFactory<CustomFieldFactory> */
    use HasFactory;

    /**
     * The field every new project starts with.
     *
     * @return array{name: string, options: list<array{name: string, color: string}>}
     */
    public static function priorityDefaults(): array
    {
        return [
            'name' => __('Priority'),
            'options' => [
                ['name' => __('Low'), 'color' => '#0ea5e9'],
                ['name' => __('Medium'), 'color' => '#f59e0b'],
                ['name' => __('High'), 'color' => '#f97316'],
                ['name' => __('Urgent'), 'color' => '#ef4444'],
            ],
        ];
    }

    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
            'show_in_list' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(CustomFieldOption::class)->orderBy('position')->orderBy('id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }
}
