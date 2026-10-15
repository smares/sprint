@props(['icon' => 'user'])

{{-- The empty round seat of a task nobody is responsible for: a dashed circle where the assignee's avatar would be. --}}
<span {{ $attributes->class('flex size-6 shrink-0 items-center justify-center rounded-full border border-dashed border-zinc-400 bg-white text-zinc-500 dark:border-zinc-500 dark:bg-zinc-800 dark:text-zinc-400')->merge(['title' => __('Unassigned'), 'data-unassigned' => '']) }}>
    <flux:icon :icon="$icon" variant="micro" />
</span>
