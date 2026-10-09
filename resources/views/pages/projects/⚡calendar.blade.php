<?php

use App\Concerns\ShowsProject;
use App\Models\Project;
use App\Models\Task;
use App\Services\DateService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use ShowsProject;

    /** How many tasks a day shows before "+ n more". */
    public const TASKS_PER_DAY = 3;

    public Project $project;

    #[Url]
    public string $month = '';

    #[Computed]
    public function monthStart(): Carbon
    {
        return (DateService::parseIsoDate($this->month.'-01') ?? now())->startOfMonth()->startOfDay();
    }

    /**
     * Drop a task on another day: the whole task moves by the distance between the two days.
     */
    public function moveToDay(int|string $taskId, string $from, string $to): void
    {
        Gate::authorize('edit', $this->project);

        $fromDay = DateService::parseIsoDate($from);
        $toDay = DateService::parseIsoDate($to);
        abort_unless($fromDay !== null && $toDay !== null, 422);

        $task = $this->project->tasks()->topLevel()->where('is_section', false)->findOrFail($taskId);
        $days = (int) $fromDay->diffInDays($toDay, false);

        if ($days !== 0 && abs($days) <= Task::MAX_SHIFT_DAYS) {
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
            ->topLevel()
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
            ->topLevel()
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
        <flux:button icon="chevron-left" wire:click="previousMonth" aria-label="{{ __('Previous month') }}" tooltip="{{ __('Previous month') }}" />
        <flux:button icon="chevron-right" wire:click="nextMonth" aria-label="{{ __('Next month') }}" tooltip="{{ __('Next month') }}" />
        <flux:button wire:click="today">{{ __('Today') }}</flux:button>
        <flux:heading size="lg" class="ms-2">{{ $this->monthStart->isoFormat('MMMM YYYY') }}</flux:heading>
    </div>

    {{-- On phones the month grid does not fit; the days with tasks are listed instead. --}}
    @php($agenda = collect($this->weeks)->flatten(1)->filter(fn ($day) => $day['inMonth'] && $day['tasks']->isNotEmpty()))
    <div class="space-y-4 sm:hidden">
        @forelse ($agenda as $day)
            <div wire:key="agenda-{{ $day['date']->toDateString() }}">
                <flux:heading size="sm" @class(['mb-1', 'text-blue-600 dark:text-blue-400' => $day['date']->isToday()])>{{ $day['date']->isoFormat('dd, L') }}</flux:heading>
                <div class="space-y-1">
                    @foreach ($day['tasks'] as $task)
                        <a wire:key="agenda-{{ $day['date']->toDateString() }}-{{ $task->id }}" href="{{ route('tasks.show', $task) }}" wire:navigate style="--badge: {{ $task->status->color }}"
                           class="color-chip block truncate rounded px-2 py-1 text-sm {{ $task->isDone() ? 'line-through opacity-60' : '' }}">{{ $task->title }}</a>
                    @endforeach
                </div>
            </div>
        @empty
            <flux:text>{{ __('No tasks with dates this month.') }}</flux:text>
        @endforelse
    </div>

    <div class="overflow-x-auto max-sm:hidden">
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
                            {{-- A click on the day itself (not on a task) opens the dialog with that day as the due date --}}
                            x-on:click="if (! $event.target.closest('a, button')) { $wire.$dispatch('new-task', { dueDate: '{{ $day['date']->toDateString() }}' }) }"
                        @endif
                        @class([
                        'group min-h-28 space-y-1 border-e border-b border-zinc-200 p-1.5 dark:border-zinc-700',
                        'bg-zinc-50/60 text-zinc-400 dark:bg-zinc-900/40' => ! $day['inMonth'],
                        'cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-700/40' => $this->canEdit,
                    ])>
                        <div class="flex items-start justify-between">
                            <div @class([
                                'text-sm',
                                'inline-flex size-6 items-center justify-center rounded-full bg-blue-600 font-semibold text-white' => $day['date']->isToday(),
                            ])>{{ $day['date']->day }}</div>

                            @if ($this->canEdit)
                                <button type="button" x-on:click.stop="$wire.$dispatch('new-task', { dueDate: '{{ $day['date']->toDateString() }}' })"
                                    class="rounded p-0.5 text-zinc-400 opacity-0 hover:bg-zinc-200 hover:text-zinc-700 focus:opacity-100 group-hover:opacity-100 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                                    aria-label="{{ __('New task on :date', ['date' => $day['date']->isoFormat('L')]) }}" title="{{ __('New task on :date', ['date' => $day['date']->isoFormat('L')]) }}">
                                    <flux:icon.plus variant="micro" />
                                </button>
                            @endif
                        </div>

                        @foreach ($day['tasks']->take($this::TASKS_PER_DAY) as $task)
                            <a wire:key="chip-{{ $day['date']->toDateString() }}-{{ $task->id }}" href="{{ route('tasks.show', $task) }}" wire:navigate
                               style="--badge: {{ $task->status->color }}"
                               @if ($this->canEdit)
                                   draggable="true"
                                   x-on:dragstart="$event.dataTransfer.setData('text/plain', JSON.stringify({ id: {{ $task->id }}, from: '{{ $day['date']->toDateString() }}' })); $event.dataTransfer.effectAllowed = 'move'"
                               @endif
                               class="color-chip block truncate rounded px-1.5 py-0.5 text-xs hover:underline {{ $task->isDone() ? 'line-through opacity-60' : '' }}">{{ $task->title }}</a>
                        @endforeach

                        @if ($day['tasks']->count() > $this::TASKS_PER_DAY)
                            <flux:text size="sm" class="px-1">{{ __('+ :count more', ['count' => $day['tasks']->count() - $this::TASKS_PER_DAY]) }}</flux:text>
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>

    @if ($this->undated > 0)
        <flux:text class="mt-4">
            {{ trans_choice('{1} :count task has no date and therefore does not appear in the calendar.|[2,*] :count tasks have no date and therefore do not appear in the calendar.', $this->undated) }}
            <flux:link :href="route('projects.show', [$project, 'dates' => 'none', 'status' => 'all'])" wire:navigate>{{ __('Show them in the list') }}</flux:link>
        </flux:text>
    @endif
</div>
