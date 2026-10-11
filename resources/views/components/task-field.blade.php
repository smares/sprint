@props(['label', 'icon' => null])

{{-- One property of a task: a quiet label (with an icon) and the value, which looks like text until it is pointed at
     (see .task-properties in app.css). --}}
<div {{ $attributes->class('grid items-start gap-1 py-1.5 sm:grid-cols-[9rem_1fr] sm:gap-3') }}>
    <div class="flex items-center gap-2 text-sm text-zinc-500 sm:min-h-8 dark:text-zinc-400">
        @if ($icon)
            <flux:icon :icon="$icon" variant="micro" class="shrink-0 text-zinc-400 dark:text-zinc-500" />
        @endif
        <span>{{ $label }}</span>
    </div>
    <div class="min-w-0">{{ $slot }}</div>
</div>
