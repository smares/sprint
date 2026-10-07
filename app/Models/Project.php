<?php

namespace App\Models;

use App\TaskStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
        $tasks = $this->tasks()->where('is_section', false)->get(['id', 'parent_id', 'status']);
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

    /**
     * Next free position among the top-level tasks.
     */
    public function nextRootPosition(): int
    {
        return ($this->tasks()->whereNull('parent_id')->max('position') ?? -1) + 1;
    }

    /**
     * Put a top-level task into the single manual order shared by list and board.
     *
     * @param  list<int>  $visibleIds  Ordered ids currently shown to the user, without the moved task.
     */
    public function placeRootTask(Task $task, array $visibleIds, int $position): void
    {
        DB::transaction(function () use ($task, $visibleIds, $position) {
            $orderedIds = $this->tasks()
                ->whereNull('parent_id')
                ->whereKeyNot($task->getKey())
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $visibleIds = array_values(array_intersect($visibleIds, $orderedIds));
            $position = max(0, min($position, count($visibleIds)));

            $insertAt = match (true) {
                $visibleIds === [] => count($orderedIds),
                $position < count($visibleIds) => array_search($visibleIds[$position], $orderedIds, true),
                default => array_search(end($visibleIds), $orderedIds, true) + 1,
            };

            array_splice($orderedIds, $insertAt, 0, [$task->getKey()]);

            foreach ($orderedIds as $index => $id) {
                Task::whereKey($id)->update(['position' => $index]);
            }
        });
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
