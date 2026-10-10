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
     * For each of the last months, oldest first: how long the tasks completed in it took from creation to done (median
     * and the time 85 % of them stayed within, in days) and which share of those with a due date was done by then.
     * A month without completed (or dated) tasks has null instead of a number.
     *
     * @return list<array{month: string, completed: int, median: ?float, p85: ?float, dated: int, on_time: ?int}>
     */
    public function monthlyCycle(Project $project, int $months): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);
        $byMonth = $this->completedSince($project, $start)->groupBy(fn (array $task) => $task['completed_at']->format('Y-m'));
        $result = [];

        for ($month = $start->copy(); $month->lessThanOrEqualTo(now()); $month->addMonth()) {
            $result[] = ['month' => $month->isoFormat('MMM YYYY'), ...$this->cycleOf($byMonth->get($month->format('Y-m'), collect()))];
        }

        return $result;
    }

    /**
     * The same figures for all tasks completed in the last days.
     *
     * @return array{completed: int, median: ?float, p85: ?float, dated: int, on_time: ?int}
     */
    public function recentCycle(Project $project, int $days): array
    {
        return $this->cycleOf($this->completedSince($project, now()->subDays($days)));
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
     * @return Collection<int, array{created_at: Carbon, completed_at: Carbon, due_date: ?Carbon}>
     */
    private function completedSince(Project $project, Carbon $since): Collection
    {
        return $project->tasks()->where('is_section', false)->where('completed_at', '>=', $since)
            ->toBase()->get(['created_at', 'completed_at', 'due_date'])
            ->map(fn (object $task) => [
                'created_at' => Carbon::parse($task->created_at),
                'completed_at' => Carbon::parse($task->completed_at),
                'due_date' => $task->due_date === null ? null : Carbon::parse($task->due_date),
            ]);
    }

    /**
     * @param  Collection<int, array{created_at: Carbon, completed_at: Carbon, due_date: ?Carbon}>  $tasks
     * @return array{completed: int, median: ?float, p85: ?float, dated: int, on_time: ?int}
     */
    private function cycleOf(Collection $tasks): array
    {
        $days = $tasks->map(fn (array $task) => max(0.0, $task['created_at']->diffInMinutes($task['completed_at']) / 1440))->sort()->values();
        $dated = $tasks->filter(fn (array $task) => $task['due_date'] !== null);
        $onTime = $dated->filter(fn (array $task) => $task['completed_at']->toDateString() <= $task['due_date']->toDateString())->count();

        return [
            'completed' => $tasks->count(),
            'median' => $this->percentile($days, 50),
            'p85' => $this->percentile($days, 85),
            'dated' => $dated->count(),
            'on_time' => $dated->isEmpty() ? null : (int) round($onTime / $dated->count() * 100),
        ];
    }

    /**
     * The value at the given percentile of sorted numbers (nearest rank), rounded to one decimal; null without numbers.
     *
     * @param  Collection<int, float>  $sorted
     */
    private function percentile(Collection $sorted, int $percent): ?float
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        return round((float) $sorted[(int) ceil($percent / 100 * $sorted->count()) - 1], 1);
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
