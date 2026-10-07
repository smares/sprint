@props(['color'])

@php
    $hex = \App\Color::normalize($color);
@endphp

<flux:badge {{ $attributes->class('color-badge') }} style="--badge: {{ $hex }}">{{ $slot }}</flux:badge>
