@props(['label'])

<div {{ $attributes->class('grid items-start gap-1 py-1.5 sm:grid-cols-[8.5rem_1fr] sm:gap-3') }}>
    <div class="text-sm text-zinc-500 sm:pt-1.5 dark:text-zinc-400">{{ $label }}</div>
    <div class="min-w-0">{{ $slot }}</div>
</div>
