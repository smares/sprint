<?php

use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public Project $project;

    #[Url]
    public string $month = '';

    public function mount(): void
    {
        Gate::authorize('view', $this->project);
    }

    public function hydrate(): void
    {
        Gate::authorize('view', $this->project);
    }

    #[Computed]
    public function monthStart(): Carbon
    {
        $parsed = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) ? Carbon::createFromFormat('Y-m-d', $this->month.'-01') : null;

        return ($parsed ?? now())->startOfMonth()->startOfDay();
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
    public function undated()
    {
        return $this->project->tasks()
            ->whereNull('parent_id')
            ->where('is_section', false)
            ->whereNull('start_date')
            ->whereNull('due_date')
            ->count();
    }

    public function rendering($view): void
    {
        $view->title('Kalender – '.$this->project->name);
    }
};
?>

<div>
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>Projekte</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <flux:heading size="xl">{{ $project->name }}</flux:heading>
        <x-project-views :project="$project" active="calendar" />
    </div>

    <div class="mb-4 flex items-center gap-2">
        <flux:button icon="chevron-left" wire:click="previousMonth" aria-label="Voriger Monat" />
        <flux:button icon="chevron-right" wire:click="nextMonth" aria-label="Nächster Monat" />
        <flux:button wire:click="today">Heute</flux:button>
        <flux:heading size="lg" class="ms-2">{{ $this->monthStart->copy()->locale('de')->translatedFormat('F Y') }}</flux:heading>
    </div>

    <div class="overflow-x-auto">
        <div class="grid min-w-[56rem] grid-cols-7 border-s border-t border-zinc-200 dark:border-zinc-700">
            @foreach ($this->weeks[0] as $weekday)
                <div class="border-e border-b border-zinc-200 bg-zinc-50 p-2 text-sm font-medium dark:border-zinc-700 dark:bg-zinc-900">
                    {{ $weekday['date']->copy()->locale('de')->isoFormat('dd') }}
                </div>
            @endforeach

            @foreach ($this->weeks as $week)
                @foreach ($week as $day)
                    <div wire:key="day-{{ $day['date']->toDateString() }}" @class([
                        'min-h-28 space-y-1 border-e border-b border-zinc-200 p-1.5 dark:border-zinc-700',
                        'bg-zinc-50/60 text-zinc-400 dark:bg-zinc-900/40' => ! $day['inMonth'],
                    ])>
                        <div @class([
                            'text-sm',
                            'inline-flex size-6 items-center justify-center rounded-full bg-blue-600 font-semibold text-white' => $day['date']->isToday(),
                        ])>{{ $day['date']->day }}</div>

                        @foreach ($day['tasks']->take(3) as $task)
                            <a wire:key="chip-{{ $day['date']->toDateString() }}-{{ $task->id }}" href="{{ route('tasks.show', $task) }}" wire:navigate
                               class="block truncate rounded px-1.5 py-0.5 text-xs hover:underline {{ match ($task->status->color) {
                                   'red' => 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-100',
                                   'orange' => 'bg-orange-100 text-orange-900 dark:bg-orange-900/40 dark:text-orange-100',
                                   'amber' => 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100',
                                   'lime' => 'bg-lime-100 text-lime-900 dark:bg-lime-900/40 dark:text-lime-100',
                                   'green' => 'bg-green-100 text-green-900 dark:bg-green-900/40 dark:text-green-100',
                                   'teal' => 'bg-teal-100 text-teal-900 dark:bg-teal-900/40 dark:text-teal-100',
                                   'sky' => 'bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-100',
                                   'blue' => 'bg-blue-100 text-blue-900 dark:bg-blue-900/40 dark:text-blue-100',
                                   'indigo' => 'bg-indigo-100 text-indigo-900 dark:bg-indigo-900/40 dark:text-indigo-100',
                                   'purple' => 'bg-purple-100 text-purple-900 dark:bg-purple-900/40 dark:text-purple-100',
                                   'pink' => 'bg-pink-100 text-pink-900 dark:bg-pink-900/40 dark:text-pink-100',
                                   default => 'bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-zinc-100',
                               } }} {{ $task->isDone() ? 'line-through opacity-60' : '' }}">{{ $task->title }}</a>
                        @endforeach

                        @if ($day['tasks']->count() > 3)
                            <flux:text size="sm" class="px-1">+ {{ $day['tasks']->count() - 3 }} weitere</flux:text>
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>

    @if ($this->undated > 0)
        <flux:text class="mt-4">{{ $this->undated }} {{ $this->undated === 1 ? 'Aufgabe hat' : 'Aufgaben haben' }} kein Datum und {{ $this->undated === 1 ? 'erscheint' : 'erscheinen' }} deshalb nicht im Kalender.</flux:text>
    @endif
</div>
