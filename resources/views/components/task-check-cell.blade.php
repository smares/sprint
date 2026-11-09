@props(['task', 'editable' => true, 'selectable' => true, 'compact' => false])

{{-- The place before a task for what is done to the task as a whole: the round check (finish or open again) and, while tasks are being
     selected, the box that adds it to the selection. The two share one cell of fixed size, so nothing moves when the selection starts or
     ends: the check goes, the box stands where it was (the box is always there, only shown while the page selects, in the browser at
     once, before the server answers). `selectable`: whether the person may select the task (not in a project they can only read).
     24px, on phones 40px for the finger, except `compact` (the cards, which stand beside the first line of the title). --}}
<span {{ $attributes->class(['inline-grid shrink-0 place-items-center', $compact ? 'size-6' : 'size-6 max-sm:size-10']) }}>
    <span class="contents" @if ($selectable) x-show="! $wire.selecting" @endif>
        <x-task-done-button :task="$task" :editable="$editable" :compact="$compact" />
    </span>

    @if ($selectable)
        <span class="contents" x-show="$wire.selecting" x-cloak>
            <x-task-select-box :task="$task" />
        </span>
    @endif
</span>
