@props(['project'])

@php
    $total = (int) $project->progress_total_count;
    $done = (int) $project->progress_done_count;
    $percent = $total > 0 ? (int) round($done / $total * 100) : 0;
    $nextDue = $project->next_due_date !== null ? \Illuminate\Support\Carbon::parse($project->next_due_date) : null;
@endphp

{{-- A project on the projects page: a band in its color, its tile and name, how far it has come, who works on it and what
     is due next. The title is the real link (new tab, keyboard); a click anywhere else on the card opens the project too.
     The card itself is no link, so it can be dragged when it is a favorite. The star reads and changes the favorites of
     the projects page around it (favorites, toggleFavorite in its x-data). --}}
<div {{ $attributes->class('relative h-full') }}>
    <flux:card
        class="board-card flex h-full cursor-pointer flex-col overflow-hidden p-0! hover:bg-zinc-50 dark:hover:bg-zinc-700/50"
        x-on:click="if ($event.target.closest('a, button')) return; ($event.metaKey || $event.ctrlKey) ? window.open($el.querySelector('a').href, '_blank') : $el.querySelector('a').click()"
    >
        <div class="h-1.5 shrink-0" style="background: {{ $project->displayColor() }}" aria-hidden="true"></div>

        <div class="flex flex-1 flex-col p-5">
            <div class="flex items-center gap-3 pe-8">
                <x-project-icon :project="$project" />
                <flux:heading size="lg" class="min-w-0 truncate">
                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="hover:underline">{{ $project->name }}</a>
                </flux:heading>
            </div>
            <flux:text class="mt-2 line-clamp-2">{{ $project->description }}</flux:text>

            {{-- Overdue and due this week only when there are any; each opens the list filtered to them --}}
            <div class="mt-4 flex flex-wrap gap-2">
                <flux:badge size="sm">{{ __(':count open', ['count' => $project->open_tasks_count]) }}</flux:badge>
                @if ($project->overdue_tasks_count > 0)
                    <a href="{{ route('projects.show', [$project, 'dates' => 'overdue']) }}" wire:navigate class="rounded-md hover:opacity-80">
                        <flux:badge size="sm" color="red" icon="exclamation-triangle">{{ __(':count overdue', ['count' => $project->overdue_tasks_count]) }}</flux:badge>
                    </a>
                @endif
                @if ($project->due_soon_tasks_count > 0)
                    <a href="{{ route('projects.show', [$project, 'dates' => 'soon']) }}" wire:navigate class="rounded-md hover:opacity-80">
                        <flux:badge size="sm" color="amber" icon="clock">{{ __(':count due this week', ['count' => $project->due_soon_tasks_count]) }}</flux:badge>
                    </a>
                @endif
            </div>

            <div class="mt-auto pt-5">
                @if ($total > 0)
                    <div class="mb-1 flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                        <span>{{ __(':done of :total done', ['done' => $done, 'total' => $total]) }}</span>
                        <span class="tabular-nums">{{ $percent }}%</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="{{ __('Progress') }}">
                        <div class="h-full rounded-full transition-[width] duration-500" style="width: {{ $percent }}%; background: {{ $project->displayColor() }}"></div>
                    </div>
                @endif

                @if ($project->members->isNotEmpty() || $nextDue)
                    <div class="mt-4 flex items-center justify-between gap-2">
                        <x-avatar-stack :users="$project->members" :max="4" />
                        @if ($nextDue)
                            {{-- Like a task's due chip (x-task-due), quiet: the overdue ones have their own badge above --}}
                            <flux:badge size="sm" icon="calendar" class="ms-auto tabular-nums" title="{{ __('Next due date') }}">{{ $nextDue->isoFormat('L') }}</flux:badge>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </flux:card>

    <flux:tooltip class="absolute! end-3 top-4" x-data="{ get starred() { return favorites.includes({{ $project->id }}) } }">
        <flux:button
            size="sm"
            variant="ghost"
            square
            x-bind:class="starred ? 'text-amber-500! hover:text-amber-600!' : 'text-zinc-400! hover:text-zinc-600! dark:hover:text-zinc-200!'"
            x-on:click="toggleFavorite({{ $project->id }})"
            x-bind:aria-pressed="starred ? 'true' : 'false'"
            x-bind:aria-label="starred ? {{ \Illuminate\Support\Js::from(__('Remove from favorites')) }} : {{ \Illuminate\Support\Js::from(__('Add to favorites')) }}"
        >
            <flux:icon.star variant="solid" class="size-4" x-show="starred" />
            <flux:icon.star variant="outline" class="size-4" x-show="! starred" />
        </flux:button>
        <flux:tooltip.content>
            <span x-show="starred">{{ __('Remove from favorites') }}</span>
            <span x-show="! starred">{{ __('Add to favorites') }}</span>
        </flux:tooltip.content>
    </flux:tooltip>
</div>
