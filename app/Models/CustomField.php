<?php

namespace App\Models;

use App\Enums\CustomFieldType;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<CustomFieldOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(CustomFieldOption::class)->orderBy('position')->orderBy('id');
    }

    /**
     * A stored value written out: the option's name for selection fields (given the option id),
     * otherwise the value itself (dates are stored as YYYY-MM-DD); null when there is none.
     */
    public function text(int|string|null $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        return $this->type === CustomFieldType::Select
            ? $this->options->firstWhere('id', (int) $stored)?->name
            : (string) $stored;
    }

    /**
     * What to store for typed text (an option's name in any case, a number, a date as YYYY-MM-DD
     * or a text of up to 500 characters); null when the text does not fit the field.
     *
     * @return array{option_id: ?int, value: ?string}|null
     */
    public function parse(string $input): ?array
    {
        $input = trim($input);

        return match ($this->type) {
            CustomFieldType::Select => ($option = $this->options->first(fn (CustomFieldOption $option) => mb_strtolower($option->name) === mb_strtolower($input)))
                ? ['option_id' => $option->id, 'value' => null]
                : null,
            CustomFieldType::Number => is_numeric($input) ? ['option_id' => null, 'value' => $input] : null,
            CustomFieldType::Date => Carbon::canBeCreatedFromFormat($input, 'Y-m-d') && Carbon::createFromFormat('!Y-m-d', $input)->toDateString() === $input
                ? ['option_id' => null, 'value' => $input]
                : null,
            CustomFieldType::Text => mb_strlen($input) <= 500 ? ['option_id' => null, 'value' => $input] : null,
        };
    }

    /**
     * @return HasMany<CustomFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }
}
