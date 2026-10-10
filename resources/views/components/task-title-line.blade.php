@props(['task', 'open' => false, 'progress' => null, 'number' => null])

{{-- A task's title in the lists with its subtask progress and tags, optionally after its row number.
     Badges are separated by plain spaces, not margins: a space at the start of a wrapped line disappears, so tags
     that wrap begin flush with the title. A pixel above and below keeps wrapped lines of badges apart. --}}
@if ($number !== null)
    <span class="me-1.5 inline-block min-w-4 select-none max-sm:hidden text-end align-baseline text-xs tabular-nums text-zinc-300 dark:text-zinc-600" data-row-number="{{ $number }}" title="{{ __('Row :number', ['number' => $number]) }}">{{ $number }}</span>@endif<x-task-title-link :task="$task" :open="$open" />
@if ($progress)
    <flux:badge size="sm" icon="list-bullet" class="my-px">{{ $progress['done'] }}/{{ $progress['total'] }}</flux:badge>
@endif
@foreach ($task->tags as $tag)
    <x-color-badge size="sm" :color="$tag->color" class="my-px">{{ $tag->name }}</x-color-badge>
@endforeach
