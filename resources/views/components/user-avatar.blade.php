@props(['user'])

{{-- A person's round profile picture, or their initials on their own color while there is none; grey while they are away
     (profile, data-absent), with the absence as title unless a tooltip already says more. Grey rather than see-through, so
     avatars stacked on top of each other stay readable. --}}
<flux:avatar circle :src="$user->avatarUrl()" :name="$user->name" :color="$user->avatarColor()" {{ $attributes->class(['grayscale' => $user->isAbsent()])->merge(['title' => $attributes->has('tooltip') ? null : $user->absenceNote(), 'data-absent' => $user->isAbsent() ? '' : null]) }} />
