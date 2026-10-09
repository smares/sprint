@props(['user'])

{{-- A person's profile picture, or their initials while there is none; faded while they are away (profile), with the
     absence as title unless a tooltip already says more. --}}
<flux:avatar :src="$user->avatarUrl()" :name="$user->name" {{ $attributes->class(['opacity-50' => $user->isAbsent()])->merge(['title' => $attributes->has('tooltip') ? null : $user->absenceNote()]) }} />
