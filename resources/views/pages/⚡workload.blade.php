<?php

use App\Models\Team;
use App\Models\User;
use App\Services\WorkloadService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    /** Whose work is shown: "me", "team:<id>" for a team of one's own (any team for admins) or "all" (admins only). */
    #[Url]
    public string $scope = 'me';

    /**
     * The teams one may look at: one's own, for admins every team.
     *
     * @return Collection<int, Team>
     */
    #[Computed]
    public function teams(): Collection
    {
        $user = auth()->user();

        return ($user->is_admin ? Team::query() : $user->teams())->orderBy('name')->get();
    }

    /**
     * The people of the chosen scope (active ones, by name); anything not allowed falls back to oneself.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        $user = auth()->user();

        if ($this->scope === 'all' && $user->is_admin) {
            return User::query()->active()->orderBy('name')->get();
        }

        if (str_starts_with($this->scope, 'team:') && ($team = $this->teams->firstWhere('id', (int) substr($this->scope, 5)))) {
            return $team->users()->active()->orderBy('name')->get();
        }

        return new Collection([$user]);
    }

    /**
     * @return list<array{start: \Illuminate\Support\Carbon, end: \Illuminate\Support\Carbon, label: string, range: string}>
     */
    #[Computed]
    public function weeks(): array
    {
        return app(WorkloadService::class)->weeks();
    }

    /**
     * @return array<int, array{overdue: list<string>, weeks: list<array{titles: list<string>, absent_days: int}>, undated: int}>
     */
    #[Computed]
    public function load(): array
    {
        return app(WorkloadService::class)->forPeople($this->people, auth()->user());
    }

    /**
     * The titles behind a number, for hovering: the first few and how many more.
     *
     * @param  list<string>  $titles
     */
    protected function titlesOf(array $titles): ?string
    {
        if ($titles === []) {
            return null;
        }

        $shown = array_slice($titles, 0, WorkloadService::TITLES);
        $more = count($titles) - count($shown);

        return implode("\n", $shown).($more > 0 ? "\n".trans_choice('and :count more|and :count more', $more) : '');
    }

    public function rendering(View $view): void
    {
        $view->title(__('Workload'));
    }
};
?>

<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="max-w-2xl">
            <flux:heading size="xl">{{ __('Workload') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Open tasks with a due date per person and week, from the projects you can see. Absences from the profile are marked, so you see in time where to hand work over.') }}</flux:text>
        </div>

        <div class="sm:w-60">
            <flux:select variant="listbox" wire:model.live="scope" :aria-label="__('Show')">
                <flux:select.option value="me">{{ __('Me') }}</flux:select.option>
                @foreach ($this->teams as $team)
                    <flux:select.option value="team:{{ $team->id }}">{{ __('Team :name', ['name' => $team->name]) }}</flux:select.option>
                @endforeach
                @if (auth()->user()->is_admin)
                    <flux:select.option value="all">{{ __('Everyone') }}</flux:select.option>
                @endif
            </flux:select>
        </div>
    </div>

    {{-- On narrow screens the weeks scroll sideways under the names, which stay in place (as in the task list) --}}
    @php($pinned = 'sticky start-0 z-10 bg-white dark:bg-zinc-800 after:pointer-events-none after:absolute after:inset-y-0 after:end-0 after:w-8 after:translate-x-full in-data-scrolled-right:after:inset-shadow-[8px_0px_8px_-8px_rgba(0,0,0,0.08)] dark:in-data-scrolled-right:after:inset-shadow-[8px_0px_8px_-8px_rgba(0,0,0,0.5)]')

    @if ($this->people->isEmpty())
        <flux:text>{{ __('Nobody in this team yet.') }}</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column :class="$pinned">{{ __('Person') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Overdue') }}</flux:table.column>
                @foreach ($this->weeks as $week)
                    <flux:table.column align="end" wire:key="week-{{ $loop->index }}"><span title="{{ $week['range'] }}">{{ $loop->first ? __('This week') : $week['label'] }}</span></flux:table.column>
                @endforeach
                <flux:table.column align="end">{{ __('No due date') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->people as $person)
                    @php($row = $this->load[$person->id])
                    <flux:table.row wire:key="person-{{ $person->id }}" data-person="{{ $person->id }}">
                        <flux:table.cell :class="$pinned">
                            <div class="flex items-center gap-2">
                                <x-user-avatar size="xs" :user="$person" />
                                <span class="font-medium text-zinc-800 dark:text-white">{{ $person->name }}</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <span title="{{ $this->titlesOf($row['overdue']) }}" @class(['font-medium text-red-600 dark:text-red-400' => $row['overdue'] !== [], 'text-zinc-300 dark:text-zinc-600' => $row['overdue'] === []])>{{ count($row['overdue']) ?: '–' }}</span>
                        </flux:table.cell>
                        @foreach ($row['weeks'] as $cell)
                            <flux:table.cell align="end" wire:key="cell-{{ $person->id }}-{{ $loop->index }}">
                                <div class="flex flex-col items-end gap-0.5">
                                    <span title="{{ $this->titlesOf($cell['titles']) }}" @class(['font-medium text-zinc-800 dark:text-white' => $cell['titles'] !== [], 'text-zinc-300 dark:text-zinc-600' => $cell['titles'] === []])>{{ count($cell['titles']) ?: '–' }}</span>
                                    @if ($cell['absent_days'] > 0)
                                        <flux:badge size="sm" :color="$cell['titles'] !== [] ? 'amber' : 'zinc'" icon="sun">{{ trans_choice(':count day away|:count days away', $cell['absent_days']) }}</flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>
                        @endforeach
                        <flux:table.cell align="end">
                            <span class="text-zinc-500 dark:text-zinc-400">{{ $row['undated'] ?: '–' }}</span>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <flux:text size="sm" class="mt-4 text-zinc-500">{{ __('Hover over a number to see the tasks. Days away count Monday to Friday; amber means there is work due while the person is away.') }}</flux:text>
    @endif
</div>
