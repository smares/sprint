@props(['user'])

{{-- A person's round profile picture, or their initials on their own color while there is none; faded while they are away
     (profile), with the absence as title unless a tooltip already says more. --}}
<flux:avatar circle :src="$user->avatarUrl()" :name="$user->name" :color="$user->avatarColor()" {{ $attributes->class(['opacity-50' => $user->isAbsent()])->merge(['title' => $attributes->has('tooltip') ? null : $user->absenceNote()]) }} />
