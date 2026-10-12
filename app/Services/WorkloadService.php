<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How much open work with a due date lies ahead for each person, week by week, next to their absence. Only tasks from
 * projects the viewer may see count (archived projects and sections do not), so nobody learns about hidden projects.
 */
class WorkloadService
{
    /** How many weeks, the current one included, the overview looks ahead. */
    public const int WEEKS = 4;

    /** How many task titles a cell names on hover. */
    public const int TITLES = 5;

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
                'label' => DateService::weekLabel($monday),
                'range' => DateService::weekRange($monday),
            ];
        }

        return $weeks;
    }

    /**
     * Per person: the overdue tasks, those due in each week with the weekdays the person is away then, and how many open
     * tasks have no due date. The database counts them; of each group only the first few tasks (TITLES, by due date) are
     * loaded, so a team with thousands of tasks costs as little as one with ten.
     *
     * @param  Collection<int, User>  $people
     * @return array<int, array{overdue: array{count: int, tasks: list<array{id: int, title: string}>}, weeks: list<array{count: int, tasks: list<array{id: int, title: string}>, absent_days: int}>, undated: int}>
     */
    public function forPeople(Collection $people, User $viewer): array
    {
        $weeks = $this->weeks();
        $tasks = Task::query()
            ->visibleTo($viewer)
            ->open()
            ->withoutSections()
            ->whereIn('assignee_id', $people->modelKeys())
            ->whereIn('project_id', Project::query()->whereNull('archived_at')->select('id'));

        // 0 for overdue, then 1 to WEEKS for the week the due date falls into (compared with the day after, as the
        // column may hold a time of midnight)
        $bucket = 'case when due_date < ? then 0 '.implode(' ', array_fill(0, count($weeks), 'when due_date < ? then ?')).' end';
        $bindings = [today()->toDateString()];

        foreach ($weeks as $index => $week) {
            $bindings[] = $week['end']->copy()->addDay()->toDateString();
            $bindings[] = $index + 1;
        }

        $dated = (clone $tasks)->whereNotNull('due_date')->where('due_date', '<', end($weeks)['end']->copy()->addDay()->toDateString());

        $counts = (clone $dated)
            ->selectRaw("assignee_id, {$bucket} as bucket, count(*) as total", $bindings)
            ->groupBy('assignee_id', 'bucket')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => ["{$row->assignee_id}:{$row->bucket}" => (int) $row->total]);

        $ranked = (clone $dated)->selectRaw(
            "id, title, assignee_id, {$bucket} as bucket, row_number() over (partition by assignee_id, {$bucket} order by due_date, id) as position",
            [...$bindings, ...$bindings],
        );

        $firstTasks = DB::query()->fromSub($ranked, 'ranked')
            ->where('position', '<=', self::TITLES)
            ->orderBy('position')
            ->get()
            ->groupBy(fn (object $row) => "{$row->assignee_id}:{$row->bucket}")
            ->map(fn ($rows) => $rows->map(fn (object $row) => ['id' => (int) $row->id, 'title' => $row->title])->values()->all());

        $undated = (clone $tasks)->whereNull('due_date')
            ->selectRaw('assignee_id, count(*) as total')
            ->groupBy('assignee_id')
            ->toBase()
            ->pluck('total', 'assignee_id');

        $group = fn (int $personId, int $bucket) => [
            'count' => $counts->get("{$personId}:{$bucket}", 0),
            'tasks' => $firstTasks->get("{$personId}:{$bucket}", []),
        ];

        $result = [];

        foreach ($people as $person) {
            $result[$person->id] = [
                'overdue' => $group($person->id, 0),
                'weeks' => array_map(fn (array $week, int $index) => [
                    ...$group($person->id, $index + 1),
                    'absent_days' => $this->absentWeekdays($person, $week['start'], $week['end']),
                ], $weeks, array_keys($weeks)),
                'undated' => (int) $undated->get($person->id, 0),
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
