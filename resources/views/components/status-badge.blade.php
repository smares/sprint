@props(['status'])

{{-- A task's status in its color, with a dot in the full color in front, so even pale statuses stand out. --}}
<x-color-badge size="sm" :color="$status->color" {{ $attributes->class('gap-1.5') }}>
    <span class="size-1.5 shrink-0 rounded-full bg-(--badge)" aria-hidden="true"></span>{{ $status->name }}
</x-color-badge>
