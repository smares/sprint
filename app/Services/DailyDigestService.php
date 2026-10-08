<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Picks the tasks that go into somebody's morning summary: overdue, due today and due in the next days.
 */
class DailyDigestService
{
    /**
     * @return array{overdue: Collection<int, Task>, today: Collection<int, Task>, upcoming: Collection<int, Task>}
     */
    public function tasksFor(User $user, ?Carbon $now = null): array
    {
        $today = ($now ?? now())->copy()->startOfDay();
        $until = $today->copy()->addDays(config('sprint.digest_days_ahead'))->endOfDay();

        $tasks = Task::query()
            ->where('is_section', false)
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $until)
            ->whereHas('status', fn (Builder $status) => $status->where('is_done', false))
            ->whereHas('project', fn (Builder $projects) => $projects->visibleTo($user)->whereNull('archived_at'))
            ->where(fn (Builder $mine) => $mine
                ->where('assignee_id', $user->getKey())
                ->orWhereHas('collaborators', fn (Builder $collaborators) => $collaborators->whereKey($user->getKey()))
            )
            ->whereDoesntHave('notificationMutes', fn (Builder $muted) => $muted->whereKey($user->getKey()))
            ->with('project:id,name')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        return [
            'overdue' => $tasks->filter(fn (Task $task) => $task->due_date->startOfDay()->lt($today))->values(),
            'today' => $tasks->filter(fn (Task $task) => $task->due_date->startOfDay()->equalTo($today))->values(),
            'upcoming' => $tasks->filter(fn (Task $task) => $task->due_date->startOfDay()->gt($today))->values(),
        ];
    }
}
