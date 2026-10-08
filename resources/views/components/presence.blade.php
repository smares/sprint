@props(['users' => [], 'label' => null])

@if ($users !== [])
    <div {{ $attributes->class('flex items-center gap-2') }} aria-live="polite">
        <div class="flex -space-x-2">
            @foreach (array_slice($users, 0, 5) as $user)
                <flux:avatar wire:key="presence-{{ $user['id'] }}" size="xs" circle :name="$user['name']" :title="$user['name']" class="ring-2 ring-white dark:ring-zinc-900" />
            @endforeach
        </div>

        @if ($label)
            <flux:text size="sm">{{ $label }}</flux:text>
        @endif
    </div>
@endif
