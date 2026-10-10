<?php

use App\Concerns\OpensTaskPanel;
use App\Concerns\ShowsProject;
use App\Models\Project;
use App\Models\Task;
use App\Services\ProjectStatisticsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use OpensTaskPanel;
    use ShowsProject;

    /** How many weeks the chart of created and completed tasks covers. */
    public const WEEKS = 12;

    /** How many of the longest-standing tasks are listed. */
    public const STUCK_TASKS = 10;

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

    #[Computed]
    public function openCount(): int
    {
        return $this->project->tasks()->open()->where('is_section', false)->count();
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
                $lastFour = array_slice($this->flow, -4);
                $created = array_sum(array_column($lastFour, 'created'));
                $completed = array_sum(array_column($lastFour, 'completed'));
            @endphp
            <div>
                <flux:heading size="lg">{{ __('Created and completed per week') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Are more tasks finished than added? If the gray bars stay higher for weeks, the backlog grows.') }}</flux:text>
            </div>

            <div class="flex flex-wrap gap-x-8 gap-y-2">
                <flux:text>{{ __('Open now') }}: <strong class="font-medium text-zinc-800 dark:text-white" data-stat="open">{{ $this->openCount }}</strong></flux:text>
                <flux:text>{{ __('Last 4 weeks') }}: <strong class="font-medium text-zinc-800 dark:text-white" data-stat="created">{{ trans_choice(':count created|:count created', $created) }}</strong>, <strong class="font-medium text-zinc-800 dark:text-white" data-stat="completed">{{ trans_choice(':count completed|:count completed', $completed) }}</strong></flux:text>
            </div>

            <flux:chart :value="$this->flow" wire:key="flow-{{ md5(json_encode($this->flow)) }}">
                <flux:chart.viewport class="aspect-[2/1] sm:aspect-[4/1]">
                    <flux:chart.svg>
                        <flux:chart.group width="70%">
                            <flux:chart.bar field="created" class="text-zinc-300 dark:text-zinc-600" radius="2" />
                            <flux:chart.bar field="completed" class="text-green-500 dark:text-green-600" radius="2" />
                        </flux:chart.group>

                        <flux:chart.axis axis="x" field="week">
                            <flux:chart.axis.tick />
                            <flux:chart.axis.line />
                        </flux:chart.axis>

                        <flux:chart.axis axis="y" tick-count="4">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>

                        <flux:chart.cursor type="area" />
                    </flux:chart.svg>
                </flux:chart.viewport>

                <flux:chart.tooltip>
                    <flux:chart.tooltip.heading field="range" />
                    <flux:chart.tooltip.value field="created" :label="__('Created')" />
                    <flux:chart.tooltip.value field="completed" :label="__('Completed')" />
                </flux:chart.tooltip>

                <div class="flex justify-center gap-4 pt-3">
                    <flux:chart.legend :label="__('Created')">
                        <flux:chart.legend.indicator class="bg-zinc-300 dark:bg-zinc-600" />
                    </flux:chart.legend>
                    <flux:chart.legend :label="__('Completed')">
                        <flux:chart.legend.indicator class="bg-green-500 dark:bg-green-600" />
                    </flux:chart.legend>
                </div>
            </flux:chart>
        </section>

        <section class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Stuck tasks') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Open tasks that have stood longest in their status, without the first status (“:status”), where waiting is normal. A good place to ask what is blocking them.', ['status' => $project->defaultStatus()->name]) }}</flux:text>
            </div>

            @if ($this->stuck->isEmpty())
                <flux:text>{{ __('No open task is beyond the first status.') }}</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Task') }}</flux:table.column>
                        <flux:table.column class="max-sm:hidden">{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('In this status') }}</flux:table.column>
                        <flux:table.column class="max-sm:hidden">{{ __('Assignee') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->stuck as $task)
                            <flux:table.row wire:key="stuck-{{ $task->id }}" data-task-id="{{ $task->id }}" data-opens-task="{{ $task->id }}" class="cursor-pointer">
                                <flux:table.cell class="min-w-48 whitespace-normal">
                                    <x-task-title-link :task="$task" :open="(string) $task->id === $openTaskId" />
                                </flux:table.cell>
                                <flux:table.cell class="max-sm:hidden">
                                    <x-color-badge size="sm" :color="$task->status->color">{{ $task->status->name }}</x-color-badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @php($days = (int) $task->in_status_since->diffInDays(now()))
                                    <span title="{{ __('since :date', ['date' => $task->in_status_since->isoFormat('L')]) }}" @class(['font-medium text-amber-600 dark:text-amber-500' => $days >= 14])>{{ trans_choice(':count day|:count days', $days) }}</span>
                                </flux:table.cell>
                                <flux:table.cell class="max-sm:hidden">
                                    @if ($task->assignee)
                                        <x-user-avatar size="xs" :user="$task->assignee" :tooltip="$task->assignee->labelledName()" />
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </section>
    </div>

    <x-task-panel :task="$this->panelTask" />
</div>
