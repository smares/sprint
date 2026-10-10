<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The numbers behind a project's statistics. Every task counts (subtasks too, every repetition on its own), sections do not.
 */
class ProjectStatisticsService
{
    /**
     * How many tasks were created and how many were completed in each of the last weeks, oldest first.
     *
     * @return list<array{week: string, range: string, created: int, completed: int}>
     */
    public function weeklyFlow(Project $project, int $weeks): array
    {
        $start = now()->startOfWeek()->subWeeks($weeks - 1);
        $tasks = fn () => $project->tasks()->where('is_section', false);

        $created = $this->countByWeek($tasks()->where('created_at', '>=', $start)->pluck('created_at'));
        $completed = $this->countByWeek($tasks()->where('completed_at', '>=', $start)->pluck('completed_at'));

        $flow = [];

        for ($week = $start->copy(); $week->lessThanOrEqualTo(now()); $week->addWeek()) {
            $key = $week->toDateString();
            $flow[] = [
                'week' => __('Week :number', ['number' => $week->isoWeek()]),
                'range' => $week->isoFormat('L').' – '.$week->copy()->endOfWeek()->isoFormat('L'),
                'created' => $created[$key] ?? 0,
                'completed' => $completed[$key] ?? 0,
            ];
        }

        return $flow;
    }

    /**
     * Open tasks that have stood longest in the status they are in, without the first open status (the backlog,
     * where waiting is normal): since their last status change, or since they were created if it never changed.
     *
     * @return Collection<int, Task>
     */
    public function stuckTasks(Project $project, int $limit): Collection
    {
        $backlog = $project->defaultStatus()->id;

        return $project->tasks()->open()->where('is_section', false)->where('status_id', '!=', $backlog)
            ->addSelect(['status_changed_at' => TaskActivity::query()
                ->selectRaw('max(created_at)')
                ->whereColumn('task_id', 'tasks.id')
                ->where('type', ActivityType::StatusChanged),
            ])
            ->with(['status', 'assignee'])
            ->get()
            ->each(fn (Task $task) => $task->setAttribute('in_status_since', Carbon::parse($task->getAttribute('status_changed_at') ?? $task->created_at)))
            ->sortBy(fn (Task $task) => $task->getAttribute('in_status_since'))
            ->take($limit)
            ->values();
    }

    /**
     * @param  Collection<int, mixed>  $times
     * @return array<string, int> Monday of the week => count
     */
    private function countByWeek(Collection $times): array
    {
        return $times->countBy(fn (mixed $time) => Carbon::parse($time)->startOfWeek()->toDateString())->all();
    }
}
