@props(['users', 'max' => 3])

@php
    $people = collect($users)->filter()->unique('id')->values();
@endphp

{{-- Several people as overlapping round avatars, each with their name (and absence) on hover; beyond `max` a "+n"
     whose tooltip names the rest. --}}
@if ($people->isNotEmpty())
    <flux:avatar.group {{ $attributes }}>
        @foreach ($people->take($max) as $person)
            <x-user-avatar size="xs" :user="$person" :tooltip="$person->labelledName()" />
        @endforeach
        @if ($people->count() > $max)
            <flux:avatar size="xs" circle :tooltip="$people->skip($max)->map->labelledName()->join(', ')">+{{ $people->count() - $max }}</flux:avatar>
        @endif
    </flux:avatar.group>
@endif
