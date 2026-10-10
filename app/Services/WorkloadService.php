<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * How much open work with a due date lies ahead for each person, week by week, next to their absence. Only tasks from
 * projects the viewer may see count (archived projects and sections do not), so nobody learns about hidden projects.
 */
class WorkloadService
{
    /** How many weeks, the current one included, the overview looks ahead. */
    public const WEEKS = 4;

    /** How many task titles a cell names on hover. */
    public const TITLES = 5;

    /**
     * The current week and the following ones: the current one counts from today, earlier days are overdue.
     *
     * @return list<array{start: Carbon, end: Carbon, label: string, range: string}>
     */
    public function weeks(): array
    {
        $weeks = [];

        for ($i = 0; $i < self::WEEKS; $i++) {
            $monday = today()->startOfWeek()->addWeeks($i);
            $weeks[] = [
                'start' => $i === 0 ? today() : $monday,
                'end' => $monday->copy()->endOfWeek()->startOfDay(),
                'label' => __('Week :number', ['number' => $monday->isoWeek()]),
                'range' => $monday->isoFormat('L').' – '.$monday->copy()->endOfWeek()->isoFormat('L'),
            ];
        }

        return $weeks;
    }

    /**
     * Per person: overdue tasks, the tasks due in each week with the weekdays the person is away then, and the open
     * tasks without a due date.
     *
     * @param  Collection<int, User>  $people
     * @return array<int, array{overdue: list<string>, weeks: list<array{titles: list<string>, absent_days: int}>, undated: int}>
     */
    public function forPeople(Collection $people, User $viewer): array
    {
        $weeks = $this->weeks();
        $tasks = Task::query()
            ->visibleTo($viewer)
            ->open()
            ->where('is_section', false)
            ->whereIn('assignee_id', $people->modelKeys())
            ->whereIn('project_id', Project::query()->whereNull('archived_at')->select('id'))
            ->where(fn ($query) => $query->whereNull('due_date')->orWhere('due_date', '<', end($weeks)['end']->copy()->addDay()->toDateString()))
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'title', 'assignee_id', 'due_date'])
            ->groupBy('assignee_id');

        $result = [];

        foreach ($people as $person) {
            $own = $tasks->get($person->id, collect());
            $dated = $own->filter(fn (Task $task) => $task->due_date !== null);

            $result[$person->id] = [
                'overdue' => $dated->filter(fn (Task $task) => $task->due_date->lt(today()))->pluck('title')->values()->all(),
                'weeks' => array_map(fn (array $week) => [
                    'titles' => $dated->filter(fn (Task $task) => $task->due_date->between($week['start'], $week['end']))->pluck('title')->values()->all(),
                    'absent_days' => $this->absentWeekdays($person, $week['start'], $week['end']),
                ], $weeks),
                'undated' => $own->count() - $dated->count(),
            ];
        }

        return $result;
    }

    /**
     * Weekdays (Monday to Friday) between the two days, both included, on which the person is away.
     */
    private function absentWeekdays(User $person, Carbon $from, Carbon $to): int
    {
        if ($person->absent_from === null || $person->absent_until === null) {
            return 0;
        }

        $start = $person->absent_from->max($from);
        $end = $person->absent_until->min($to);
        $days = 0;

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $days += $day->isWeekday() ? 1 : 0;
        }

        return $days;
    }
}
