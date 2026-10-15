@props(['at', 'format' => 'L LT'])

@php
    // A point in time on the clock of the person looking at it ("L LT": 2026-10-25 02:30). The title names the time zone
    // when it is not the application's.
    $local = auth()->user()?->inTimezone($at) ?? $at->copy()->setTimezone(config('app.timezone'));
    $zone = $local->getTimezone()->getName();
@endphp

<time datetime="{{ $local->toIso8601String() }}" @if ($zone !== config('app.timezone')) title="{{ $zone }}" @endif {{ $attributes }}>{{ $local->isoFormat($format) }}</time>
