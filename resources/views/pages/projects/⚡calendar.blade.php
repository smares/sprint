<?php

use App\Concerns\ShowsProject;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use ShowsProject;

    public Project $project;

    #[Url]
    public string $month = '';

    #[Computed]
    public function monthStart(): Carbon
    {
        $parsed = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) ? Carbon::createFromFormat('Y-m-d', $this->month.'-01') : null;

        return ($parsed ?? now())->startOfMonth()->startOfDay();
    }

    /**
     * Drop a task on another day: the whole task moves by the distance between the two days.
     */
    public function moveToDay(int|string $taskId, string $from, string $to): void
    {
        Gate::authorize('edit', $this->project);

        $isDate = fn (string $value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && Carbon::hasFormat($value, 'Y-m-d');
        abort_unless($isDate($from) && $isDate($to), 422);

        $task = $this->project->tasks()->whereNull('parent_id')->where('is_section', false)->findOrFail($taskId);
        $days = (int) Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay(), false);

        if ($days !== 0 && abs($days) <= 3650) {
            $task->shiftDates($days);
        }

        unset($this->weeks, $this->undated);
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart->copy()->subMonth()->format('Y-m');
        unset($this->monthStart, $this->weeks, $this->undated);
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart->copy()->addMonth()->format('Y-m');
        unset($this->monthStart, $this->weeks, $this->undated);
    }

    public function today(): void
    {
        $this->month = now()->format('Y-m');
        unset($this->monthStart, $this->weeks, $this->undated);
    }

    /**
     * Days of the visible months grouped by week, each with the tasks that are on that day.
     *
     * @return list<list<array{date: Carbon, inMonth: bool, tasks: \Illuminate\Support\Collection<int, Task>}>>
     */
    #[Computed]
    public function weeks(): array
    {
        $from = $this->monthStart->copy()->startOfWeek();
        $to = $this->monthStart->copy()->endOfMonth()->endOfWeek()->startOfDay();

        $tasks = $this->project->tasks()
            ->whereNull('parent_id')
            ->where('is_section', false)
            ->overlapping($from, $to)
            ->with('status')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $weeks = [];

        for ($day = $from->copy(); $day <= $to; $day->addDay()) {
            $date = $day->copy();

            $weeks[intdiv($date->diffInDays($from), 7)][] = [
                'date' => $date,
                'inMonth' => $date->month === $this->monthStart->month,
                'tasks' => $tasks->filter(fn (Task $task) => $task->spanStart()->startOfDay() <= $date && $task->spanEnd()->startOfDay() >= $date)->values(),
            ];
        }

        return $weeks;
    }

    /**
     * Tasks without any date cannot appear in the calendar.
     */
    #[Computed]
    public function undated(): int
    {
        return $this->project->tasks()
            ->whereNull('parent_id')
            ->where('is_section', false)
            ->whereNull('start_date')
            ->whereNull('due_date')
            ->count();
    }

    public function rendering(View $view): void
    {
        $view->title(__('Calendar – :project', ['project' => $this->project->name]));
    }
};
?>

<div>
    <x-project-header :project="$project" active="calendar" :presence="$this->presenceChannel()" />

    <div class="mb-4 flex items-center gap-2">
        <flux:button icon="chevron-left" wire:click="previousMonth" aria-label="{{ __('Previous month') }}" />
        <flux:button icon="chevron-right" wire:click="nextMonth" aria-label="{{ __('Next month') }}" />
        <flux:button wire:click="today">{{ __('Today') }}</flux:button>
        <flux:heading size="lg" class="ms-2">{{ $this->monthStart->isoFormat('MMMM YYYY') }}</flux:heading>
    </div>

    <div class="overflow-x-auto">
        <div class="grid min-w-[56rem] grid-cols-7 border-s border-t border-zinc-200 dark:border-zinc-700">
            @foreach ($this->weeks[0] as $weekday)
                <div class="border-e border-b border-zinc-200 bg-zinc-50 p-2 text-sm font-medium dark:border-zinc-700 dark:bg-zinc-900">
                    {{ $weekday['date']->isoFormat('dd') }}
                </div>
            @endforeach

            @foreach ($this->weeks as $week)
                @foreach ($week as $day)
                    <div wire:key="day-{{ $day['date']->toDateString() }}"
                        @if ($this->canEdit)
                            x-data="{ over: false }"
                            x-on:dragover.prevent="over = true"
                            x-on:dragleave="over = false"
                            x-on:drop.prevent="over = false; const task = JSON.parse($event.dataTransfer.getData('text/plain') || '{}'); if (task.id) { $wire.moveToDay(task.id, task.from, '{{ $day['date']->toDateString() }}') }"
                            x-bind:class="over && 'ring-2 ring-inset ring-blue-400'"
                        @endif
                        @class([
                        'min-h-28 space-y-1 border-e border-b border-zinc-200 p-1.5 dark:border-zinc-700',
                        'bg-zinc-50/60 text-zinc-400 dark:bg-zinc-900/40' => ! $day['inMonth'],
                    ])>
                        <div @class([
                            'text-sm',
                            'inline-flex size-6 items-center justify-center rounded-full bg-blue-600 font-semibold text-white' => $day['date']->isToday(),
                        ])>{{ $day['date']->day }}</div>

                        @foreach ($day['tasks']->take(3) as $task)
                            <a wire:key="chip-{{ $day['date']->toDateString() }}-{{ $task->id }}" href="{{ route('tasks.show', $task) }}" wire:navigate
                               style="--badge: {{ $task->status->color }}"
                               @if ($this->canEdit)
                                   draggable="true"
                                   x-on:dragstart="$event.dataTransfer.setData('text/plain', JSON.stringify({ id: {{ $task->id }}, from: '{{ $day['date']->toDateString() }}' })); $event.dataTransfer.effectAllowed = 'move'"
                               @endif
                               class="color-chip block truncate rounded px-1.5 py-0.5 text-xs hover:underline {{ $task->isDone() ? 'line-through opacity-60' : '' }}">{{ $task->title }}</a>
                        @endforeach

                        @if ($day['tasks']->count() > 3)
                            <flux:text size="sm" class="px-1">{{ __('+ :count more', ['count' => $day['tasks']->count() - 3]) }}</flux:text>
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>

    @if ($this->undated > 0)
        <flux:text class="mt-4">{{ trans_choice('{1} :count task has no date and therefore does not appear in the calendar.|[2,*] :count tasks have no date and therefore do not appear in the calendar.', $this->undated) }}</flux:text>
    @endif
</div>
