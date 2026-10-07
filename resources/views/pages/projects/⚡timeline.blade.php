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
    /** Days shown at once. */
    public const DAYS = 42;

    public Project $project;

    #[Url]
    public string $from = '';

    public function mount(): void
    {
        Gate::authorize('view', $this->project);
    }

    public function hydrate(): void
    {
        Gate::authorize('view', $this->project);
    }

    #[Computed]
    public function start(): Carbon
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) ? Carbon::make($this->from) : null;

        return ($parsed ?? now())->startOfWeek()->startOfDay();
    }

    #[Computed]
    public function end(): Carbon
    {
        return $this->start->copy()->addDays(self::DAYS - 1);
    }

    private function moveBy(int $days): void
    {
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
        $this->from = now()->startOfWeek()->toDateString();
        unset($this->start, $this->end, $this->rows, $this->outside);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{task: Task, first: int, last: int}>
     */
    #[Computed]
    public function rows()
    {
        return $this->project->tasks()
            ->whereNull('parent_id')
            ->where('is_section', false)
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
        $tasks = $this->project->tasks()->whereNull('parent_id')->where('is_section', false);

        $undated = (clone $tasks)->whereNull('start_date')->whereNull('due_date')->count();
        $dated = (clone $tasks)->where(fn ($q) => $q->whereNotNull('start_date')->orWhereNotNull('due_date'))->count();

        return ['dated' => $dated - $this->rows->count(), 'undated' => $undated];
    }

    public function rendering($view): void
    {
        $view->title('Zeitleiste – '.$this->project->name);
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
        <x-project-views :project="$project" active="timeline" />
    </div>

    <div class="mb-4 flex items-center gap-2">
        <flux:button icon="chevron-left" wire:click="earlier" aria-label="Früher" />
        <flux:button icon="chevron-right" wire:click="later" aria-label="Später" />
        <flux:button wire:click="today">Heute</flux:button>
        <flux:text class="ms-2">{{ $this->start->format('d.m.Y') }} – {{ $this->end->format('d.m.Y') }}</flux:text>
    </div>

    @php($days = collect(range(0, $this::DAYS - 1))->map(fn ($offset) => $this->start->copy()->addDays($offset)))
    @php($todayColumn = now()->between($this->start, $this->end->copy()->endOfDay()) ? (int) $this->start->diffInDays(now()->startOfDay()) + 1 : null)

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <div class="min-w-max">
            <div class="flex border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="sticky start-0 z-10 w-56 shrink-0 border-e border-zinc-200 bg-zinc-50 p-2 text-sm font-medium dark:border-zinc-700 dark:bg-zinc-900">Aufgabe</div>
                <div class="grid" style="grid-template-columns: repeat({{ $days->count() }}, 2rem)">
                    @foreach ($days as $day)
                        <div wire:key="head-{{ $day->toDateString() }}" @class([
                            'py-1 text-center text-xs leading-tight',
                            'bg-zinc-100 dark:bg-zinc-800' => $day->isWeekend(),
                            'font-bold text-blue-600' => $day->isToday(),
                        ])>
                            @if ($day->day === 1 || $loop->first)
                                <div class="whitespace-nowrap font-semibold">{{ $day->copy()->locale('de')->translatedFormat('M') }}</div>
                            @else
                                <div>&nbsp;</div>
                            @endif
                            <div>{{ $day->day }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            @forelse ($this->rows as $row)
                @php($task = $row['task'])
                <div wire:key="row-{{ $task->id }}" class="flex border-b border-zinc-100 last:border-b-0 dark:border-zinc-800">
                    <div class="sticky start-0 z-10 flex w-56 shrink-0 items-center gap-1 border-e border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-800">
                        <a href="{{ route('tasks.show', $task) }}" wire:navigate @class(['truncate text-sm hover:underline', 'line-through text-zinc-400' => $task->isDone()])>{{ $task->title }}</a>
                        @if ($task->isBlocked())
                            <flux:icon.lock-closed variant="micro" class="shrink-0 text-amber-500" title="Blockiert" />
                        @endif
                    </div>
                    <div class="relative grid items-center" style="grid-template-columns: repeat({{ $days->count() }}, 2rem)">
                        @if ($todayColumn)
                            <div class="pointer-events-none absolute inset-y-0 w-px bg-blue-400" style="inset-inline-start: {{ ($todayColumn - 1) * 2 + 1 }}rem"></div>
                        @endif
                        <a href="{{ route('tasks.show', $task) }}" wire:navigate title="{{ $task->title }}: {{ $task->spanStart()->format('d.m.') }} – {{ $task->spanEnd()->format('d.m.Y') }}"
                           class="my-1.5 block h-5 truncate rounded px-1 text-xs leading-5 text-white {{ match ($task->status->color) {
                               'red' => 'bg-red-500',
                               'orange' => 'bg-orange-500',
                               'amber' => 'bg-amber-500',
                               'lime' => 'bg-lime-600',
                               'green' => 'bg-green-600',
                               'teal' => 'bg-teal-600',
                               'sky' => 'bg-sky-500',
                               'blue' => 'bg-blue-600',
                               'indigo' => 'bg-indigo-600',
                               'purple' => 'bg-purple-600',
                               'pink' => 'bg-pink-500',
                               default => 'bg-zinc-500',
                           } }} {{ $task->isDone() ? 'opacity-50' : '' }}"
                           style="grid-column: {{ $row['first'] }} / {{ $row['last'] + 1 }}; grid-row: 1">{{ $row['last'] - $row['first'] >= 2 ? $task->title : '' }}</a>
                    </div>
                </div>
            @empty
                <div class="p-6"><flux:text>In diesen Wochen gibt es keine Aufgaben mit Datum.</flux:text></div>
            @endforelse
        </div>
    </div>

    @if ($this->outside['dated'] > 0 || $this->outside['undated'] > 0)
        <flux:text class="mt-4">
            @if ($this->outside['dated'] > 0)
                {{ $this->outside['dated'] }} {{ $this->outside['dated'] === 1 ? 'Aufgabe liegt' : 'Aufgaben liegen' }} außerhalb dieser Wochen.
            @endif
            @if ($this->outside['undated'] > 0)
                {{ $this->outside['undated'] }} {{ $this->outside['undated'] === 1 ? 'Aufgabe hat' : 'Aufgaben haben' }} kein Datum.
            @endif
        </flux:text>
    @endif
</div>
