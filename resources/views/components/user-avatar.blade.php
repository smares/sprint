@props(['user'])

{{-- A person's profile picture, or their initials while there is none. --}}
<flux:avatar :src="$user->avatarUrl()" :name="$user->name" {{ $attributes }} />
