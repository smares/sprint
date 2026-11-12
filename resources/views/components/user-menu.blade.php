{{-- The menu under the person's avatar (sidebar on large screens, header on phones): appearance, background, profile, shortcuts, log out
     and the running version. --}}
<flux:menu class="min-w-60!">
    <div class="px-2 py-1.5">
        <flux:text size="sm" class="mb-1.5">{{ __('Appearance') }}</flux:text>
        <flux:radio.group aria-label="{{ __('Appearance') }}" x-data x-init="$watch('$flux.appearance', (appearance) => saveInterface({ appearance }))" variant="segmented" x-model="$flux.appearance" size="sm">
            <flux:radio value="light" icon="sun" aria-label="{{ __('Light') }}" />
            <flux:radio value="dark" icon="moon" aria-label="{{ __('Dark') }}" />
            <flux:radio value="system" icon="computer-desktop" aria-label="{{ __('System') }}" />
        </flux:radio.group>
        <flux:text size="sm" class="mt-3 mb-1.5">{{ __('Background') }}</flux:text>
        {{-- Starts from what the layer was drawn with (the server's state); a change is told to the layer (ambient.js) and kept like the appearance --}}
        <flux:radio.group aria-label="{{ __('Background') }}" x-data="{ mode: document.querySelector('.ambient')?.dataset.mode ?? 'animated' }" x-on:ambient-background.window="mode = $event.detail?.mode ?? mode" x-init="$watch('mode', (mode) => { $dispatch('ambient-background', { mode }); saveInterface({ ambient: mode }) })" variant="segmented" x-model="mode" size="sm">
            @foreach (\App\Enums\AmbientBackground::cases() as $mode)
                <flux:radio :value="$mode->value" :icon="$mode->icon()" :aria-label="$mode->label()" :title="$mode->label()" />
            @endforeach
        </flux:radio.group>
    </div>
    <flux:menu.separator />
    <flux:menu.item icon="user" href="{{ route('profile') }}" wire:navigate>{{ __('Profile') }}</flux:menu.item>
    <flux:modal.trigger name="keyboard-shortcuts">
        <flux:menu.item icon="command-line" kbd="?">{{ __('Keyboard shortcuts') }}</flux:menu.item>
    </flux:modal.trigger>
    <form method="POST" action="{{ route('logout') }}" data-logout>
        @csrf
        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle">{{ __('Log out') }}</flux:menu.item>
    </form>
    <flux:menu.separator />
    <div class="px-2 py-1.5" data-app-version>
        <flux:text size="sm">{{ config('app.name') }} {{ preg_match('/^\d/', (string) config('sprint.version')) ? 'v' : '' }}{{ config('sprint.version') }}</flux:text>
    </div>
</flux:menu>
