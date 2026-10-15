@props(['users', 'max' => 3, 'unassigned' => false])

@php
    $people = collect($users)->filter()->unique('id')->values();
    $shown = $people->take($max);
@endphp

{{-- Several people as overlapping round avatars, each with their name (and absence) on hover: the first one (the main
     person, e.g. the assignee) at the left and in front, the others behind it and trailing off to the right (the
     z-index falls from one to the next, where Flux would stack later ones on top). Beyond `max` a "+n" whose tooltip
     names the rest. With `unassigned` (a task that has people but no assignee) the main seat in front is an empty
     dashed circle, so nobody is mistaken for the assignee. --}}
@if ($people->isNotEmpty())
    <flux:avatar.group {{ $attributes }}>
        @if ($unassigned)
            <x-unassigned-avatar class="relative ring-2 ring-white dark:ring-zinc-900" :style="'z-index: '.($shown->count() + 2)" />
        @endif
        @foreach ($shown as $person)
            <x-user-avatar size="xs" :user="$person" :tooltip="$person->labelledName()" class="relative" :style="'z-index: '.($shown->count() + 1 - $loop->index)" />
        @endforeach
        @if ($people->count() > $max)
            <flux:avatar size="xs" circle :tooltip="$people->skip($max)->map->labelledName()->join(', ')" class="relative" style="z-index: 0">+{{ $people->count() - $max }}</flux:avatar>
        @endif
    </flux:avatar.group>
@endif
