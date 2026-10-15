<?php

use App\Concerns\OpensTaskPanel;
use App\Concerns\ShowsProject;
use App\Models\Project;
use App\Models\Task;
use App\Services\ProjectStatisticsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use OpensTaskPanel;
    use ShowsProject;

    /** How many weeks the chart of created and completed tasks covers. */
    public const int WEEKS = 12;

    /** How many of the longest-standing tasks are listed. */
    public const int STUCK_TASKS = 10;

    /** How many months the charts of cycle time and punctuality cover. */
    public const int MONTHS = 6;

    /** The period the headline figures of cycle time and punctuality look back on. */
    public const int RECENT_DAYS = 90;

    /** From how many days in the same status a stuck task's time is highlighted. */
    public const int LONG_IN_STATUS_DAYS = 14;

    /** About how many steps the value axes have. */
    private const int Y_TICKS = 4;

    /** The full weeks summed up above the weekly chart (the current one has only begun). */
    private const int SUMMED_WEEKS = 4;

    public Project $project;

    /**
     * @return list<array{week: string, range: string, created: int, completed: int}>
     */
    #[Computed]
    public function flow(): array
    {
        return app(ProjectStatisticsService::class)->weeklyFlow($this->project, self::WEEKS);
    }

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function stuck(): Collection
    {
        return app(ProjectStatisticsService::class)->stuckTasks($this->project, self::STUCK_TASKS);
    }

    /**
     * @return list<array{month: string, completed: int, median: ?float, p85: ?float, dated: int, on_time: ?int}>
     */
    #[Computed]
    public function cycle(): array
    {
        return app(ProjectStatisticsService::class)->monthlyCycle($this->project, self::MONTHS);
    }

    /**
     * The months that have something to show for a figure: a line would draw a month without data as 0.
     *
     * @return list<array{month: string, completed: int, median: ?float, p85: ?float, dated: int, on_time: ?int}>
     */
    protected function monthsWith(string $field): array
    {
        return array_values(array_filter($this->cycle, fn (array $month) => $month[$field] !== null));
    }

    /**
     * @return array{completed: int, median: ?float, p85: ?float, dated: int, on_time: ?int}
     */
    #[Computed]
    public function recent(): array
    {
        return app(ProjectStatisticsService::class)->recentCycle($this->project, self::RECENT_DAYS);
    }

    /**
     * A number of days like "4.5 days" in the person's language, or a dash.
     */
    protected function days(?float $days): string
    {
        return $days === null ? '–' : trans_choice(':count day|:count days', $days, ['count' => Number::format($days, maxPrecision: 1, locale: app()->getLocale())]);
    }

    #[Computed]
    public function openCount(): int
    {
        return $this->project->tasks()->open()->withoutSections()->count();
    }

    /**
     * Whole-number ticks from 0 to just above the highest value, in about Y_TICKS steps of 1, 2, 5, 10, …, so no line
     * runs past the top of the axis and no tick shows half a task or day.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return list<int>
     */
    protected function ticksFor(array $rows, array $fields): array
    {
        $highest = 1.0;

        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $highest = max($highest, (float) ($row[$field] ?? 0));
            }
        }

        $rough = max(1, (int) ceil($highest / self::Y_TICKS));
        $magnitude = 10 ** (int) floor(log10($rough));
        $step = (int) (collect([1, 2, 5, 10])->first(fn (int $factor) => $factor * $magnitude >= $rough) * $magnitude);

        return range(0, (int) (ceil($highest / $step) * $step), $step);
    }

    public function rendering(View $view): void
    {
        $view->title(__('Statistics – :project', ['project' => $this->project->name]));
    }
};
?>

<div>
    <x-project-header :project="$project" active="statistics" :presence="$this->presenceChannel()" />

    <div class="space-y-10">
        <section class="space-y-4">
            @php
                $lastFour = array_slice($this->flow, -self::SUMMED_WEEKS - 1, self::SUMMED_WEEKS);
                $created = array_sum(array_column($lastFour, 'created'));
                $completed = array_sum(array_column($lastFour, 'completed'));
            @endphp
            <div>
                <flux:heading level="2" size="lg">{{ __('Created and completed per week') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Are more tasks finished than added? If the gray line stays above the green one for weeks, the backlog grows.') }}</flux:text>
            </div>

            <div class="flex flex-wrap gap-x-8 gap-y-2">
                <flux:text>{{ __('Open now') }}: <strong class="font-medium text-zinc-800 dark:text-white" data-stat="open">{{ $this->openCount }}</strong></flux:text>
                <flux:text>{{ __('Last :count full weeks', ['count' => self::SUMMED_WEEKS]) }}: <strong class="font-medium text-zinc-800 dark:text-white" data-stat="created">{{ trans_choice(':count created|:count created', $created) }}</strong>, <strong class="font-medium text-zinc-800 dark:text-white" data-stat="completed">{{ trans_choice(':count completed|:count completed', $completed) }}</strong></flux:text>
            </div>

            <div class="surface p-4 sm:p-6">
                <flux:chart :value="$this->flow" wire:key="flow-{{ md5(json_encode($this->flow)) }}" class="grid gap-4">
                    {{-- The week under the pointer, otherwise the current one --}}
                    <flux:chart.summary class="flex flex-wrap items-end gap-x-8 gap-y-2">
                        <div>
                            <flux:text size="sm">{{ __('Created') }}</flux:text>
                            <flux:heading size="xl" class="mt-1 tabular-nums"><flux:chart.summary.value field="created" /></flux:heading>
                        </div>
                        <div>
                            <flux:text size="sm">{{ __('Completed') }}</flux:text>
                            <flux:heading size="xl" class="mt-1 tabular-nums text-green-600 dark:text-green-500"><flux:chart.summary.value field="completed" /></flux:heading>
                        </div>
                        <flux:text size="sm" class="tabular-nums"><flux:chart.summary.value field="range" /></flux:text>
                    </flux:chart.summary>

                    <flux:chart.viewport class="aspect-[3/2] sm:aspect-[3/1] xl:aspect-[4/1]">
                        <flux:chart.svg>
                            <flux:chart.line field="created" class="text-zinc-400 dark:text-zinc-500" curve="none" stroke-dasharray="4 4" />
                            <flux:chart.point field="created" class="text-zinc-400 dark:text-zinc-500" r="3" />
                            <flux:chart.line field="completed" class="text-green-500" curve="none" />
                            <flux:chart.area field="completed" class="text-green-500/10" curve="none" />
                            <flux:chart.point field="completed" class="text-green-500" r="3" />

                            <flux:chart.axis axis="x" field="week">
                                <flux:chart.axis.tick />
                                <flux:chart.axis.line />
                            </flux:chart.axis>

                            <flux:chart.axis axis="y" :tick-values="$this->ticksFor($this->flow, ['created', 'completed'])" :format="['maximumFractionDigits' => 0]">
                                <flux:chart.axis.grid />
                                <flux:chart.axis.tick />
                            </flux:chart.axis>

                            <flux:chart.cursor class="text-zinc-400" stroke-dasharray="4,4" />
                        </flux:chart.svg>
                    </flux:chart.viewport>

                    <div class="flex justify-center gap-4">
                        <flux:chart.legend :label="__('Created')">
                            <flux:chart.legend.indicator class="bg-zinc-400 dark:bg-zinc-500" />
                        </flux:chart.legend>
                        <flux:chart.legend :label="__('Completed')">
                            <flux:chart.legend.indicator class="bg-green-500" />
                        </flux:chart.legend>
                    </div>
                </flux:chart>
            </div>
        </section>

        <div class="grid gap-10 lg:grid-cols-2">
            <section class="space-y-4">
                <div>
                    <flux:heading level="2" size="lg">{{ __('Cycle time') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('How long a task takes from being created to done. Half of them are done within the median, 85 % within the second figure: a realistic answer to “when will it be ready?”.') }}</flux:text>
                </div>

                <div class="flex flex-wrap gap-x-8 gap-y-2">
                    <flux:text>{{ __('Median') }}: <strong class="font-medium text-zinc-800 dark:text-white" data-stat="median">{{ $this->days($this->recent['median']) }}</strong></flux:text>
                    <flux:text>{{ __('85 % within') }}: <strong class="font-medium text-zinc-800 dark:text-white" data-stat="p85">{{ $this->days($this->recent['p85']) }}</strong></flux:text>
                    <flux:text class="text-zinc-500">{{ trans_choice('last :days days, :count task|last :days days, :count tasks', $this->recent['completed'], ['days' => self::RECENT_DAYS]) }}</flux:text>
                </div>

                @php($cycleMonths = $this->monthsWith('median'))
                <div class="surface p-4 sm:p-6">
                    @if ($cycleMonths === [])
                        <flux:text>{{ __('No task was completed in the last months.') }}</flux:text>
                    @else
                    <flux:chart :value="$cycleMonths" wire:key="cycle-{{ md5(json_encode($cycleMonths)) }}">
                        <flux:chart.viewport class="aspect-[3/2] sm:aspect-[5/2] lg:aspect-[2/1]">
                            <flux:chart.svg>
                                <flux:chart.line field="p85" class="text-amber-500" curve="none" />
                                <flux:chart.point field="p85" class="text-amber-500" r="3" />
                                <flux:chart.line field="median" class="text-blue-500" curve="none" />
                                <flux:chart.point field="median" class="text-blue-500" r="3" />

                                <flux:chart.axis axis="x" field="month">
                                    <flux:chart.axis.tick />
                                    <flux:chart.axis.line />
                                </flux:chart.axis>

                                <flux:chart.axis axis="y" :tick-values="$this->ticksFor($cycleMonths, ['median', 'p85'])" :format="['maximumFractionDigits' => 0]">
                                    <flux:chart.axis.grid />
                                    <flux:chart.axis.tick />
                                </flux:chart.axis>

                                <flux:chart.cursor class="text-zinc-400" stroke-dasharray="4,4" />
                            </flux:chart.svg>
                        </flux:chart.viewport>

                        <flux:chart.tooltip>
                            <flux:chart.tooltip.heading field="month" />
                            <flux:chart.tooltip.value field="median" :label="__('Median (days)')" />
                            <flux:chart.tooltip.value field="p85" :label="__('85 % within (days)')" />
                            <flux:chart.tooltip.value field="completed" :label="__('Completed')" />
                        </flux:chart.tooltip>

                        <div class="flex justify-center gap-4 pt-3">
                            <flux:chart.legend :label="__('Median')">
                                <flux:chart.legend.indicator class="bg-blue-500" />
                            </flux:chart.legend>
                            <flux:chart.legend :label="__('85 % within')">
                                <flux:chart.legend.indicator class="bg-amber-500" />
                            </flux:chart.legend>
                        </div>
                    </flux:chart>
                    @endif
                </div>
            </section>

            <section class="space-y-4">
                <div>
                    <flux:heading level="2" size="lg">{{ __('Done on time') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Of the completed tasks with a due date, how many were done by it. If the share drops, the dates are too tight or there is too much at once.') }}</flux:text>
                </div>

                <div class="flex flex-wrap gap-x-8 gap-y-2">
                    <flux:text>{{ __('On time') }}: <strong class="font-medium text-zinc-800 dark:text-white" data-stat="on-time">{{ $this->recent['on_time'] === null ? '–' : $this->recent['on_time'].' %' }}</strong></flux:text>
                    <flux:text class="text-zinc-500">{{ trans_choice('last :days days, :count task with a due date|last :days days, :count tasks with a due date', $this->recent['dated'], ['days' => self::RECENT_DAYS]) }}</flux:text>
                </div>

                @php($punctualMonths = $this->monthsWith('on_time'))
                <div class="surface p-4 sm:p-6">
                    @if ($punctualMonths === [])
                        <flux:text>{{ __('No task with a due date was completed in the last months.') }}</flux:text>
                    @else
                    <flux:chart :value="$punctualMonths" wire:key="on-time-{{ md5(json_encode($punctualMonths)) }}">
                        <flux:chart.viewport class="aspect-[3/2] sm:aspect-[5/2] lg:aspect-[2/1]">
                            <flux:chart.svg>
                                <flux:chart.line field="on_time" class="text-green-500" curve="none" />
                                <flux:chart.area field="on_time" class="text-green-500/10" curve="none" />
                                <flux:chart.point field="on_time" class="text-green-500" r="3" />

                                <flux:chart.axis axis="x" field="month">
                                    <flux:chart.axis.tick />
                                    <flux:chart.axis.line />
                                </flux:chart.axis>

                                <flux:chart.axis axis="y" tick-values="[0, 25, 50, 75, 100]" tick-suffix=" %">
                                    <flux:chart.axis.grid />
                                    <flux:chart.axis.tick />
                                </flux:chart.axis>

                                <flux:chart.cursor class="text-zinc-400" stroke-dasharray="4,4" />
                            </flux:chart.svg>
                        </flux:chart.viewport>

                        <flux:chart.tooltip>
                            <flux:chart.tooltip.heading field="month" />
                            <flux:chart.tooltip.value field="on_time" :label="__('On time')" suffix=" %" />
                            <flux:chart.tooltip.value field="dated" :label="__('With a due date')" />
                        </flux:chart.tooltip>
                    </flux:chart>
                    @endif
                </div>
            </section>
        </div>

        <section class="space-y-4">
            <div>
                <flux:heading level="2" size="lg">{{ __('Stuck tasks') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Open tasks that have stood longest in their status, without the first status (“:status”), where waiting is normal. A good place to ask what is blocking them.', ['status' => $project->defaultStatus()->name]) }}</flux:text>
            </div>

            @if ($this->stuck->isEmpty())
                <x-empty-state icon="check-badge" :heading="__('Nothing is stuck')" :text="__('Every open task is still in the first status.')" />
            @else
                <div class="surface px-3 [--flux-bleed:0.75rem]">
                    <flux:table bleed container:class="scroll-shadows rounded-[inherit]">
                        <flux:table.columns>
                            <flux:table.column class="pinned-column">{{ __('Task') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column>{{ __('In this status') }}</flux:table.column>
                            <flux:table.column>{{ __('Assignee') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->stuck as $task)
                                <flux:table.row wire:key="stuck-{{ $task->id }}" data-task-id="{{ $task->id }}" data-opens-task="{{ $task->id }}" class="cursor-pointer">
                                    <flux:table.cell class="pinned-column min-w-48 whitespace-normal max-sm:min-w-[45vw]">
                                        <x-task-title-link :task="$task" :open="(string) $task->id === $openTaskId" />
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <x-status-badge :status="$task->status" />
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @php($days = (int) $task->in_status_since->diffInDays(now()))
                                        <span title="{{ __('since :date', ['date' => $task->in_status_since->isoFormat('L')]) }}" @class(['font-medium text-amber-600 dark:text-amber-500' => $days >= $this::LONG_IN_STATUS_DAYS])>{{ trans_choice(':count day|:count days', $days) }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($task->assignee)
                                            <x-user-avatar size="xs" :user="$task->assignee" :tooltip="$task->assignee->labelledName()" />
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </section>
    </div>

    <x-task-panel :task="$this->panelTask" />
</div>
