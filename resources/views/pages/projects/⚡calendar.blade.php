<?php

use App\Concerns\EditsTasksInBulk;
use App\Concerns\RefreshesOnTaskChanges;
use App\Concerns\ShowsProject;
use App\Models\Project;
use App\Models\Task;
use App\Services\DateService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use EditsTasksInBulk, RefreshesOnTaskChanges, ShowsProject;

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

        $task = $this->project->tasks()->topLevel()->withoutSections()->findOrFail($taskId);
        $days = (int) $fromDay->diffInDays($toDay, false);

        if ($days !== 0 && abs($days) <= Task::MAX_SHIFT_DAYS) {
            $task->shiftDates($days);
        }

        unset($this->weeks, $this->undated);
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart->copy()->subMonth()->format('Y-m');
        $this->selected = [];
        unset($this->monthStart, $this->weeks, $this->undated);
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart->copy()->addMonth()->format('Y-m');
        $this->selected = [];
        unset($this->monthStart, $this->weeks, $this->undated);
    }

    public function today(): void
    {
        $this->month = now()->format('Y-m');
        $this->selected = [];
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
            ->withoutSections()
            ->overlapping($from, $to)
            ->with('status')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $weeks = [];

        for ($day = $from->copy(); $day <= $to; $day->addDay()) {
            $date = $day->copy();

            $weeks[intdiv((int) $from->diffInDays($date), 7)][] = [
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
            ->withoutSections()
            ->whereNull('start_date')
            ->whereNull('due_date')
            ->count();
    }

    /**
     * The calendar has no filters: every top-level task of the project can be selected.
     */
    protected function datesNeededToShowTask(): array
    {
        return ['due_date'];
    }

    protected function filteredTasks(): HasMany
    {
        return $this->project->tasks()->topLevel();
    }

    protected function forgetTasksAfterBulk(): void
    {
        unset($this->weeks, $this->undated);
    }

    public function rendering(View $view): void
    {
        $view->title(__('Calendar – :project', ['project' => $this->project->name]));
    }
};
?>

<div @class(['pb-40' => $selecting]) x-bind:class="{ 'pb-40': $wire.selecting }" data-wide>
    @php
        $offersSelecting = $this->canEdit && collect($this->weeks)->flatten(1)->contains(fn ($day) => $day['tasks']->isNotEmpty());
    @endphp
    <x-project-header :project="$project" active="calendar" :presence="$this->presenceChannel()">
        <x-slot name="selection">
            @if ($offersSelecting)
                <x-selection-toggle instant class="sm:hidden" x-show="! $wire.selecting" />
            @endif
        </x-slot>
    </x-project-header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <flux:button icon="chevron-left" wire:click="previousMonth" aria-label="{{ __('Previous month') }}" tooltip="{{ __('Previous month') }}" />
        <flux:button icon="chevron-right" wire:click="nextMonth" aria-label="{{ __('Next month') }}" tooltip="{{ __('Next month') }}" />
        <flux:button wire:click="today">{{ __('Today') }}</flux:button>
        <flux:heading size="lg" class="ms-2">{{ $this->monthStart->isoFormat('MMMM YYYY') }}</flux:heading>

        @if ($offersSelecting)
            <x-selection-toggle instant class="ms-auto max-sm:hidden" x-show="! $wire.selecting" />
        @endif
    </div>

    {{-- On phones the month grid does not fit; the days with tasks are listed instead. --}}
    @php($agenda = collect($this->weeks)->flatten(1)->filter(fn ($day) => $day['inMonth'] && $day['tasks']->isNotEmpty()))
    <div class="space-y-4 sm:hidden">
        @forelse ($agenda as $day)
            <div wire:key="agenda-{{ $day['date']->toDateString() }}">
                <flux:heading size="sm" @class(['mb-1', 'text-accent-content' => $day['date']->isToday()])>{{ $day['date']->isoFormat('dd, L') }}</flux:heading>
                <div class="space-y-1">
                    @foreach ($day['tasks'] as $task)
                        <div wire:key="agenda-{{ $day['date']->toDateString() }}-{{ $task->id }}" class="flex items-center gap-1">
                            <x-task-done-button :task="$task" :editable="$this->canEdit" compact />
                            @if ($this->canEdit)
                                <template x-if="$wire.selecting"><x-task-select-box :task="$task" /></template>
                            @endif
                            <a href="{{ route('tasks.show', $task) }}" x-on:click="$openTask($event, {{ $task->id }})" style="--badge: {{ $task->status->color }}"
                               class="color-chip block min-w-0 flex-1 truncate rounded px-2 py-1 text-sm {{ $task->isDone() ? 'line-through opacity-60' : '' }}">{{ $task->title }}</a>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <x-empty-state icon="calendar-days" :heading="__('No dates this month')" :text="__('Tasks show here once they have a due or start date.')" />
        @endforelse
    </div>

    <div class="scroll-shadows rounded-xl max-sm:hidden" data-fills-height>
        <div class="surface overflow-x-auto" data-scroll-edges>
            {{-- The surface draws the outer border, so the last column and the last week leave out their own --}}
            <div class="grid min-w-[56rem] grid-cols-7 [&>*:nth-child(7n)]:border-e-0 [&>*:nth-last-child(-n+7)]:border-b-0" data-calendar-month>
                @foreach ($this->weeks[0] as $weekday)
                    <div class="sticky top-0 z-10 border-e border-b border-zinc-200 bg-zinc-50 p-2 text-sm font-medium dark:border-zinc-700 dark:bg-zinc-900">
                        {{ $weekday['date']->isoFormat('dd') }}
                    </div>
                @endforeach

                @foreach ($this->weeks as $week)
                    @foreach ($week as $day)
                        <div wire:key="day-{{ $day['date']->toDateString() }}"
                            {{-- The browser selects at once (`$wire.selecting`), so the day stands still then without the server drawing it anew --}}
                            @if ($this->canEdit)
                                x-data="{ over: false }"
                                x-on:dragover="if (! $wire.selecting) { $event.preventDefault(); over = true }"
                                x-on:dragleave="over = false"
                                x-on:drop.prevent="over = false; if ($wire.selecting) { return }; const task = JSON.parse($event.dataTransfer.getData('text/plain') || '{}'); if (task.id) { $wire.moveToDay(task.id, task.from, '{{ $day['date']->toDateString() }}') }"
                                x-bind:class="{ 'ring-2 ring-inset ring-accent': over, 'cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-700/40': ! $wire.selecting }"
                                {{-- A click on the day itself (not on a task) opens the dialog with that day as the due date --}}
                                x-on:click="if (! $wire.selecting && ! $event.target.closest('a, button')) { $wire.$dispatch('new-task', { dueDate: '{{ $day['date']->toDateString() }}' }) }"
                            @endif
                            @class([
                            'group min-h-28 space-y-1 border-e border-b border-zinc-200 p-1.5 dark:border-zinc-700',
                            'bg-zinc-50/60 text-zinc-400 dark:bg-zinc-900/40' => ! $day['inMonth'],
                        ])>
                            <div class="flex items-start justify-between">
                                <div @class([
                                    'text-sm',
                                    'inline-flex size-6 items-center justify-center rounded-full bg-accent font-semibold text-accent-foreground' => $day['date']->isToday(),
                                ])>{{ $day['date']->day }}</div>

                                @if ($this->canEdit)
                                    <button type="button" x-show="! $wire.selecting" x-on:click.stop="$wire.$dispatch('new-task', { dueDate: '{{ $day['date']->toDateString() }}' })"
                                        class="rounded p-0.5 text-zinc-400 opacity-0 hover:bg-zinc-200 hover:text-zinc-700 focus:opacity-100 group-hover:opacity-100 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                                        aria-label="{{ __('New task on :date', ['date' => $day['date']->isoFormat('L')]) }}" title="{{ __('New task on :date', ['date' => $day['date']->isoFormat('L')]) }}">
                                        <flux:icon.plus variant="micro" />
                                    </button>
                                @endif
                            </div>

                            @foreach ($day['tasks']->take($this::TASKS_PER_DAY) as $task)
                                <div wire:key="chip-{{ $day['date']->toDateString() }}-{{ $task->id }}" class="group/chip relative">
                                    <a href="{{ route('tasks.show', $task) }}" x-on:click="$openTask($event, {{ $task->id }})"
                                       style="--badge: {{ $task->status->color }}"
                                       @if ($this->canEdit)
                                           draggable="true"
                                           x-bind:class="{ 'ps-6': $wire.selecting, '[@media(hover:none)]:pe-7': ! $wire.selecting }"
                                           x-on:dragstart="if ($wire.selecting) { $event.preventDefault(); return }; $event.dataTransfer.setData('text/plain', JSON.stringify({ id: {{ $task->id }}, from: '{{ $day['date']->toDateString() }}' })); $event.dataTransfer.effectAllowed = 'move'"
                                       @endif
                                       @class([
                                           'color-chip block truncate rounded px-1.5 py-0.5 text-xs hover:underline',
                                           'line-through opacity-60' => $task->isDone(),
                                       ])>{{ $task->title }}</a>
                                    @if ($this->canEdit)
                                        <template x-if="$wire.selecting">
                                            <span class="absolute inset-y-0 start-1 flex items-center" x-on:click.stop>
                                                <x-task-select-box :task="$task" />
                                            </span>
                                        </template>
                                        {{-- Over the end of the title while the chip is pointed at or focused (the cell is too narrow for a permanent check, and
                                             the title keeps its place); on screens without a pointer it is always there and the title leaves room --}}
                                        <span x-show="! $wire.selecting" class="absolute inset-y-0 end-0 hidden items-center rounded-e bg-white/90 group-focus-within/chip:flex group-hover/chip:flex dark:bg-zinc-800/90 [@media(hover:none)]:flex" x-on:click.stop>
                                            <x-task-done-button :task="$task" />
                                        </span>
                                    @endif
                                </div>
                            @endforeach

                            @if ($day['tasks']->count() > $this::TASKS_PER_DAY)
                                <flux:text size="sm" class="px-1">{{ __('+ :count more', ['count' => $day['tasks']->count() - $this::TASKS_PER_DAY]) }}</flux:text>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>
    </div>

    @if ($this->undated > 0)
        <flux:text class="mt-4">
            {{ trans_choice('{1} :count task has no date and therefore does not appear in the calendar.|[2,*] :count tasks have no date and therefore do not appear in the calendar.', $this->undated) }}
            <flux:link variant="ghost" :href="route('projects.show', [$project, 'dates' => 'none', 'status' => 'all'])" wire:navigate>{{ __('Show them in the list') }}</flux:link>
        </flux:text>
    @endif

    {{-- The bar is always there for who may edit, shown as soon as the browser selects; the dialog needs what the server knows --}}
    @if ($this->canEdit)
        <x-selection-bar x-show="$wire.selecting" x-cloak />
    @endif

    @if ($selecting && $this->canEdit)
        <x-bulk-edit-modal :choices="$this->bulkChoices" />
    @endif
</div>
