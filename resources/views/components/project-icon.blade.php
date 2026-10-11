@props(['project', 'size' => 'md'])

{{-- A project's tile: its emoji or first letter on its color (a tint of it, like the badges), to tell projects apart at a glance. --}}
<span
    {{ $attributes->class([
        'color-chip inline-flex shrink-0 items-center justify-center font-semibold leading-none select-none',
        'size-5 rounded text-[0.6875rem]' => $size === 'sm',
        'size-8 rounded-lg text-base' => $size === 'md',
        'size-10 rounded-xl text-xl' => $size === 'lg',
    ]) }}
    style="--badge: {{ $project->displayColor() }}"
    aria-hidden="true"
    data-project-icon
>{{ $project->displayIcon() }}</span>
