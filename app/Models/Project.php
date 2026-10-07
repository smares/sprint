<?php

namespace App\Models;

use App\TaskStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'archived_at'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Done and total counts of all descendants for every task that has subtasks.
     *
     * @return array<int, array{done: int, total: int}>
     */
    public function subtaskProgress(): array
    {
        $tasks = $this->tasks()->get(['id', 'parent_id', 'status']);
        $childrenByParent = $tasks->groupBy('parent_id');
        $progress = [];

        $count = function (int $id) use (&$count, &$progress, $childrenByParent): array {
            $done = 0;
            $total = 0;

            foreach ($childrenByParent->get($id, []) as $child) {
                [$childDone, $childTotal] = $count($child->id);
                $total += 1 + $childTotal;
                $done += ($child->status === TaskStatus::Done ? 1 : 0) + $childDone;
            }

            if ($total > 0) {
                $progress[$id] = ['done' => $done, 'total' => $total];
            }

            return [$done, $total];
        };

        foreach ($childrenByParent->get(null, []) as $root) {
            $count($root->id);
        }

        return $progress;
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
