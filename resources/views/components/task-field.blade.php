@props(['label', 'icon' => null, 'lazy' => null])

{{-- One property of a task: a quiet label (with an icon) and the value, which looks like text until it is pointed at
     (see .task-properties in app.css). On phones label and value stack, so the groups get more room between them.
     lazy: the name of the picker in it whose choices the component sends on demand (loadPicker, see lazyPicker in app.js):
     when the value is focused, clicked or used with the keyboard (not by merely pointing at it, see lazyPicker). --}}
<div {{ $attributes->class('grid items-start gap-1 py-2.5 sm:grid-cols-[9rem_1fr] sm:gap-3 sm:py-1.5') }}>
    <div class="flex items-center gap-2 text-sm text-zinc-500 sm:min-h-8 dark:text-zinc-400">
        @if ($icon)
            <flux:icon :icon="$icon" variant="micro" class="shrink-0 text-zinc-400 dark:text-zinc-500" />
        @endif
        <span>{{ $label }}</span>
    </div>
    <div class="min-w-0"
        @if ($lazy)
            x-data="lazyPicker('{{ $lazy }}')" x-on:pointerdown="load()" x-on:focusin="load()" x-on:keydown.capture="load(); hold($event)"
        @endif
    >{{ $slot }}</div>
</div>
