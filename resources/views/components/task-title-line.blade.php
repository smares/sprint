@props(['task', 'progress' => null, 'number' => null])

{{-- A task's title in the lists with its subtask progress and tags, optionally after its row number.
     Badges are separated by plain spaces, not margins: a space at the start of a wrapped line disappears, so tags
     that wrap begin flush with the title. A pixel above and below keeps wrapped lines of badges apart. --}}
@if ($number !== null)
    {{-- The number has a column of its own: a title that wraps continues under the title, not under the number --}}
    <span class="flex items-baseline gap-1.5">
        <span class="inline-block min-w-4 shrink-0 select-none max-sm:hidden text-end text-xs tabular-nums text-zinc-500" data-row-number="{{ $number }}" title="{{ __('Row :number', ['number' => $number]) }}">{{ $number }}</span>
        <span class="min-w-0">
@endif
<x-task-title-link :task="$task" />
@if ($progress)
    <flux:badge size="sm" icon="list-bullet" class="my-px">{{ $progress['done'] }}/{{ $progress['total'] }}</flux:badge>
@endif
@foreach ($task->tags as $tag)
    <x-color-badge size="sm" :color="$tag->color" class="my-px">{{ $tag->name }}</x-color-badge>
@endforeach
@if ($number !== null)
        </span>
    </span>
@endif
