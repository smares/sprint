<?php

namespace App\Models;

use App\Casts\ColorCast;
use Database\Factories\TaskStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'name', 'color', 'position', 'is_done'])]
class TaskStatus extends Model
{
    /** @use HasFactory<TaskStatusFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_done' => 'boolean', 'color' => ColorCast::class];
    }

    /**
     * The statuses every new project starts with.
     *
     * @return list<array{name: string, color: string, position: int, is_done: bool}>
     */
    public static function defaults(): array
    {
        return [
            ['name' => __('Open'), 'color' => '#71717a', 'position' => 0, 'is_done' => false],
            ['name' => __('In progress'), 'color' => '#3b82f6', 'position' => 1, 'is_done' => false],
            ['name' => __('Done'), 'color' => '#22c55e', 'position' => 2, 'is_done' => true],
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
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'status_id');
    }
}
