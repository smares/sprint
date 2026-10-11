@props(['icon' => null, 'emoji' => null, 'heading', 'text' => null])

{{-- When a page has nothing to show: a friendly picture (an emoji, or an icon on a soft orange disc with a few sparkles)
     with a heading and a sentence, centred on a white surface. Actions can go in the slot. --}}
<div {{ $attributes->class('surface flex flex-col items-center px-6 py-12 text-center') }} data-empty-state>
    <div class="relative mb-5" aria-hidden="true">
        <span class="absolute -start-3 top-1 size-1.5 rounded-full bg-orange-300 dark:bg-orange-500/60"></span>
        <span class="absolute -end-2 -top-1 size-2 rounded-full bg-amber-300 dark:bg-amber-500/60"></span>
        <span class="absolute -end-4 bottom-2 size-1 rounded-full bg-orange-400 dark:bg-orange-400/60"></span>
        <div class="flex size-16 items-center justify-center rounded-full bg-orange-50 text-3xl ring-8 ring-orange-50/50 dark:bg-orange-500/10 dark:ring-orange-500/5">
            @if ($emoji)
                {{ $emoji }}
            @elseif ($icon)
                <flux:icon :icon="$icon" class="size-7 text-orange-500" />
            @endif
        </div>
    </div>
    <flux:heading size="lg">{{ $heading }}</flux:heading>
    @if ($text)
        <flux:text class="mt-1 max-w-md">{{ $text }}</flux:text>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
