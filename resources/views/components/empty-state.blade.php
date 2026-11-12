@props(['icon' => null, 'emoji' => null, 'heading', 'text' => null])

{{-- When a page has nothing to show: a friendly picture (an emoji, or an icon on a soft blue disc with a few sparkles)
     with a heading and a sentence, centered on a white surface. Actions can go in the slot. --}}
<div {{ $attributes->class('surface flex flex-col items-center px-6 py-12 text-center') }} data-empty-state>
    <div class="relative mb-5" aria-hidden="true">
        <span class="absolute -start-3 top-1 size-1.5 rounded-full bg-stage-blue/40 dark:bg-stage-blue/60"></span>
        <span class="absolute -end-2 -top-1 size-2 rounded-full bg-stage-pink/50 dark:bg-stage-pink/60"></span>
        <span class="absolute -end-4 bottom-2 size-1 rounded-full bg-stage-blue/50 dark:bg-[#7c9bff]/60"></span>
        <div class="flex size-16 items-center justify-center rounded-full bg-stage-blue/10 text-3xl ring-8 ring-stage-blue/5 dark:bg-[#7c9bff]/10 dark:ring-[#7c9bff]/5">
            @if ($emoji)
                {{ $emoji }}
            @elseif ($icon)
                <flux:icon :icon="$icon" class="size-7 text-stage-blue dark:text-[#7c9bff]" />
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
