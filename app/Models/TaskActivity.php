<?php

namespace App\Models;

use App\Enums\ActivityType;
use Database\Factories\TaskActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'user_id', 'type', 'data'])]
class TaskActivity extends Model
{
    /** @use HasFactory<TaskActivityFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['type' => ActivityType::class, 'data' => 'array'];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Dates are stored as YYYY-MM-DD; entries written before that still hold DD.MM.YYYY or MM/DD/YYYY.
     */
    private function isoDate(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match (true) {
            (bool) preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $parts) => "{$parts[3]}-{$parts[2]}-{$parts[1]}",
            (bool) preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $parts) => "{$parts[3]}-{$parts[1]}-{$parts[2]}",
            default => $value,
        };
    }

    /**
     * What happened, as a sentence that continues after the person's name.
     */
    public function sentence(): string
    {
        $data = $this->data ?? [];

        foreach (['from', 'to'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $this->isoDate($data[$key]);
            }
        }

        return $this->type->sentence($data);
    }
}
