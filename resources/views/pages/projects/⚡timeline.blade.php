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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use EditsTasksInBulk, RefreshesOnTaskChanges, ShowsProject;

    /** Days shown at once. */
    public const DAYS = 42;

    /** Width of one day column. */
    public const DAY_WIDTH = '2rem';

    public Project $project;

    #[Url]
    public string $from = '';

    /**
     * A bar was dragged: moved as a whole, or one of its edges stretched or shrunk.
     *
     * @param  'move'|'start'|'end'  $mode
     */
    public function reschedule(int|string $taskId, string $mode, int $days): void
    {
        Gate::authorize('edit', $this->project);

        abort_unless(in_array($mode, ['move', 'start', 'end'], true), 422);

        $task = $this->project->tasks()->topLevel()->withoutSections()->findOrFail($taskId);

        if ($days === 0 || abs($days) > Task::MAX_SHIFT_DAYS) {
            return;
        }

        $mode === 'move' ? $task->shiftDates($days) : $task->resizeSpan($mode, $days);

        unset($this->rows);
    }

    #[Computed]
    public function start(): Carbon
    {
        return (DateService::parseIsoDate($this->from) ?? now())->startOfWeek()->startOfDay();
    }

    #[Computed]
    public function end(): Carbon
    {
        return $this->start->copy()->addDays(self::DAYS - 1);
    }

    private function moveBy(int $days): void
    {
        $this->selected = [];
        $this->from = $this->start->copy()->addDays($days)->toDateString();
        unset($this->start, $this->end, $this->rows, $this->outside);
    }

    public function earlier(): void
    {
        $this->moveBy(-14);
    }

    public function later(): void
    {
        $this->moveBy(14);
    }

    public function today(): void
    {
        $this->selected = [];
        $this->from = now()->startOfWeek()->toDateString();
        unset($this->start, $this->end, $this->rows, $this->outside);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{task: Task, first: int, last: int}>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->project->tasks()
            ->topLevel()
            ->withoutSections()
            ->overlapping($this->start, $this->end)
            ->with(['status', 'blockers.status'])
            ->orderByRaw('coalesce(start_date, due_date)')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (Task $task) => [
                'task' => $task,
                'first' => max(0, (int) $this->start->diffInDays($task->spanStart()->startOfDay(), false)) + 1,
                'last' => min(self::DAYS - 1, (int) $this->start->diffInDays($task->spanEnd()->startOfDay(), false)) + 1,
            ]);
    }

    /**
     * Dated tasks that are outside the shown weeks, and tasks without dates.
     *
     * @return array{dated: int, undated: int}
     */
    #[Computed]
    public function outside(): array
    {
        $tasks = $this->project->tasks()->topLevel()->withoutSections();

        $undated = (clone $tasks)->whereNull('start_date')->whereNull('due_date')->count();
        $dated = (clone $tasks)->where(fn ($q) => $q->whereNotNull('start_date')->orWhereNotNull('due_date'))->count();

        return ['dated' => $dated - $this->rows->count(), 'undated' => $undated];
    }

    /**
     * The timeline has no filters: every top-level task of the project can be selected.
     */
    protected function filteredTasks(): HasMany
    {
        return $this->project->tasks()->topLevel();
    }

    protected function forgetTasksAfterBulk(): void
    {
        unset($this->rows, $this->outside);
    }

    public function rendering(View $view): void
    {
        $view->title(__('Timeline – :project', ['project' => $this->project->name]));
    }
};
?>

<div @class(['pb-40' => $selecting]) data-wide>
    <x-project-header :project="$project" active="timeline" :presence="$this->presenceChannel()" />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <flux:button icon="chevron-left" wire:click="earlier" aria-label="{{ __('Earlier') }}" tooltip="{{ __('Earlier') }}" />
        <flux:button icon="chevron-right" wire:click="later" aria-label="{{ __('Later') }}" tooltip="{{ __('Later') }}" />
        <flux:button wire:click="today">{{ __('Today') }}</flux:button>
        <flux:text class="ms-2 whitespace-nowrap">{{ $this->start->isoFormat('L') }} – {{ $this->end->isoFormat('L') }}</flux:text>

        @if ($this->canEdit && ! $selecting)
            <x-selection-toggle class="ms-auto" />
        @endif
    </div>

    @php($days = collect(range(0, $this::DAYS - 1))->map(fn ($offset) => $this->start->copy()->addDays($offset)))
    @php($todayColumn = now()->between($this->start, $this->end->copy()->endOfDay()) ? (int) $this->start->diffInDays(now()->startOfDay()) + 1 : null)

    @if ($this->rows->isEmpty())
        <x-empty-state icon="calendar-days" :heading="__('No dates in these weeks')" :text="__('Tasks show here once they have a due or start date.')" />
    @else
        <div class="scroll-shadows rounded-xl" data-fills-height>
            <div class="surface overflow-x-auto" data-scroll-edges>
                <div class="min-w-max">
                    <div class="sticky top-0 z-20 flex border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="pinned-shadow sticky start-0 z-10 w-32 shrink-0 sm:w-56 border-e border-zinc-200 bg-zinc-50 p-2 text-sm font-medium dark:border-zinc-700 dark:bg-zinc-900">{{ __('Task') }}</div>
                        <div class="grid" style="grid-template-columns: repeat({{ $days->count() }}, {{ $this::DAY_WIDTH }})">
                            @foreach ($days as $day)
                                <div wire:key="head-{{ $day->toDateString() }}" @class([
                                    'py-1 text-center text-xs leading-tight',
                                    'bg-zinc-100 dark:bg-zinc-800' => $day->isWeekend(),
                                    'font-bold text-accent-content' => $day->isToday(),
                                ])>
                                    @if ($day->day === 1 || $loop->first)
                                        <div class="whitespace-nowrap font-semibold">{{ $day->isoFormat('MMM') }}</div>
                                    @else
                                        <div>&nbsp;</div>
                                    @endif
                                    <div>{{ $day->day }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @foreach ($this->rows as $row)
                        @php($task = $row['task'])
                        <div wire:key="row-{{ $task->id }}" class="flex border-b border-zinc-100 last:border-b-0 dark:border-zinc-800">
                            <div class="pinned-shadow sticky start-0 z-10 flex w-32 shrink-0 sm:w-56 items-center gap-1 border-e border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-800">
                                <x-task-done-button :task="$task" :editable="$this->canEdit" compact />
                                @if ($selecting)
                                    <x-task-select-box :task="$task" />
                                @endif
                                <a href="{{ route('tasks.show', $task) }}" x-on:click="$openTask($event, {{ $task->id }})" @class(['truncate text-sm hover:underline', 'line-through text-zinc-400' => $task->isDone()])>{{ $task->title }}</a>
                                @if ($task->isBlocked())
                                    <flux:icon.lock-closed variant="micro" class="shrink-0 text-amber-500" title="{{ __('Blocked') }}" />
                                @endif
                            </div>
                            <div class="relative grid items-center" data-days="{{ $days->count() }}" style="grid-template-columns: repeat({{ $days->count() }}, {{ $this::DAY_WIDTH }})">
                                @if ($todayColumn)
                                    <div class="pointer-events-none absolute inset-y-0 w-px bg-accent" style="inset-inline-start: {{ ($todayColumn - 1) * 2 + 1 }}rem"></div>
                                @endif
                                @php($clippedStart = $task->spanStart()->startOfDay() < $this->start)
                                @php($clippedEnd = $task->spanEnd()->startOfDay() > $this->end->copy()->startOfDay())
                                <a href="{{ route('tasks.show', $task) }}" x-on:click="$openTask($event, {{ $task->id }})" title="{{ $task->title }}: {{ $task->spanStart()->isoFormat('L') }} – {{ $task->spanEnd()->isoFormat('L') }}"
                                   @if ($this->canEdit && ! $selecting)
                                       draggable="false"
                                       x-data="timelineBar({{ $task->id }})"
                                       x-on:pointerdown="begin($event, 'move')"
                                       x-on:pointermove="move($event)"
                                       x-on:pointerup="finish()"
                                       x-on:pointercancel="cancel()"
                                       x-on:click.capture="suppressClick($event)"
                                   @endif
                                   class="color-chip relative my-1.5 block h-5 truncate rounded px-1 text-xs leading-5 {{ $task->isDone() ? 'opacity-50' : '' }} {{ $this->canEdit && ! $selecting ? 'cursor-grab touch-none select-none' : '' }}"
                                   style="grid-column: {{ $row['first'] }} / {{ $row['last'] + 1 }}; grid-row: 1; --badge: {{ $task->status->color }}">{{ $row['last'] - $row['first'] >= 2 ? $task->title : '' }}
                                    @if ($this->canEdit && ! $selecting)
                                        @unless ($clippedStart)
                                            <span class="absolute inset-y-0 start-0 w-1.5 cursor-ew-resize" aria-hidden="true" x-on:pointerdown.stop="begin($event, 'start')"></span>
                                        @endunless
                                        @unless ($clippedEnd)
                                            <span class="absolute inset-y-0 end-0 w-1.5 cursor-ew-resize" aria-hidden="true" x-on:pointerdown.stop="begin($event, 'end')"></span>
                                        @endunless
                                    @endif
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if ($this->outside['dated'] > 0 || $this->outside['undated'] > 0)
        <flux:text class="mt-4">
            @if ($this->outside['dated'] > 0)
                {{ trans_choice('{1} :count task is outside these weeks.|[2,*] :count tasks are outside these weeks.', $this->outside['dated']) }}
            @endif
            @if ($this->outside['undated'] > 0)
                {{ trans_choice('{1} :count task has no date.|[2,*] :count tasks have no date.', $this->outside['undated']) }}
                <flux:link :href="route('projects.show', [$project, 'dates' => 'none', 'status' => 'all'])" wire:navigate>{{ __('Show them in the list') }}</flux:link>
            @endif
        </flux:text>
    @endif

    @if ($selecting)
        <x-selection-bar />

        <x-bulk-edit-modal :project="$project" />
    @endif
</div>
