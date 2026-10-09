<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="min-h-screen">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ isset($title) ? $title.' – '.config('app.name') : config('app.name') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <meta name="theme-color" content="#18181b">
        @if ($realtime = app(\App\Services\RealtimeService::class)->clientConfig())
            <script>window.sprintRealtime = @js($realtime)</script>
            @vite(['resources/css/app.css', 'resources/js/realtime.js', 'resources/js/app.js'])
        @else
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @auth
            <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" aria-label="{{ __('Menu') }}" />

                <x-app-brand class="max-lg:hidden" />

                <flux:navbar class="ms-4 max-lg:hidden">
                    <flux:navbar.item href="{{ route('projects.index') }}" :current="request()->routeIs('projects.*')" wire:navigate>{{ __('Projects') }}</flux:navbar.item>
                    <flux:navbar.item href="{{ route('tasks.mine') }}" :current="request()->routeIs('tasks.mine')" wire:navigate>{{ __('My tasks') }}</flux:navbar.item>
                    @can('administer')
                        <flux:navbar.item href="{{ route('admin.teams') }}" :current="request()->routeIs('admin.teams')" wire:navigate>{{ __('Teams') }}</flux:navbar.item>
                        <flux:navbar.item href="{{ route('admin.users') }}" :current="request()->routeIs('admin.users')" wire:navigate>{{ __('Users') }}</flux:navbar.item>
                    @endcan
                </flux:navbar>

                <flux:spacer />

                <flux:modal.trigger name="command-palette" shortcut="cmd.k">
                    <flux:input as="button" size="sm" icon="magnifying-glass" placeholder="{{ __('Search or jump to…') }}" kbd="⌘K" class="mx-2 hidden w-64 sm:block" aria-label="{{ __('Open command palette') }}" />
                </flux:modal.trigger>

                <flux:modal.trigger name="command-palette">
                    <flux:button variant="ghost" icon="magnifying-glass" aria-label="{{ __('Find') }}" tooltip="{{ __('Find') }}" class="sm:hidden" />
                </flux:modal.trigger>

                <flux:modal.trigger name="command-palette" shortcut="ctrl.k">
                    <span class="hidden"></span>
                </flux:modal.trigger>

                <livewire:notification-bell />

                <flux:dropdown position="bottom" align="end">
                    <flux:profile :name="auth()->user()->name" initials="{{ auth()->user()->initials() }}" :avatar="auth()->user()->avatarUrl()" class="max-sm:[&>span.truncate]:hidden" />
                    <flux:menu class="min-w-60!">
                        <div class="px-2 py-1.5">
                            <flux:text size="sm" class="mb-1.5">{{ __('Appearance') }}</flux:text>
                            <flux:radio.group x-data variant="segmented" x-model="$flux.appearance" size="sm">
                                <flux:radio value="light" icon="sun" aria-label="{{ __('Light') }}" />
                                <flux:radio value="dark" icon="moon" aria-label="{{ __('Dark') }}" />
                                <flux:radio value="system" icon="computer-desktop" aria-label="{{ __('System') }}" />
                            </flux:radio.group>
                        </div>
                        <flux:menu.separator />
                        <flux:menu.item icon="user" href="{{ route('profile') }}" wire:navigate>{{ __('Profile') }}</flux:menu.item>
                        <flux:modal.trigger name="keyboard-shortcuts">
                            <flux:menu.item icon="command-line" kbd="?">{{ __('Keyboard shortcuts') }}</flux:menu.item>
                        </flux:modal.trigger>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle">{{ __('Log out') }}</flux:menu.item>
                        </form>
                        <flux:menu.separator />
                        <div class="px-2 py-1.5" data-app-version>
                            <flux:text size="sm">{{ config('app.name') }} {{ preg_match('/^\d/', (string) config('sprint.version')) ? 'v' : '' }}{{ config('sprint.version') }}</flux:text>
                        </div>
                    </flux:menu>
                </flux:dropdown>
            </flux:header>

            <flux:sidebar stashable sticky class="border-e border-zinc-200 bg-zinc-50 lg:hidden dark:border-zinc-700 dark:bg-zinc-900">
                <flux:sidebar.toggle class="lg:hidden" icon="x-mark" aria-label="{{ __('Close menu') }}" />

                <x-app-brand class="px-2" />

                <flux:navlist variant="outline">
                    <flux:navlist.item icon="folder" href="{{ route('projects.index') }}" :current="request()->routeIs('projects.*')" wire:navigate>{{ __('Projects') }}</flux:navlist.item>
                    <flux:navlist.item icon="check-circle" href="{{ route('tasks.mine') }}" :current="request()->routeIs('tasks.mine')" wire:navigate>{{ __('My tasks') }}</flux:navlist.item>
                    <flux:navlist.item icon="bell" href="{{ route('inbox') }}" :current="request()->routeIs('inbox')" wire:navigate>{{ __('Inbox') }}</flux:navlist.item>
                    <flux:navlist.item icon="magnifying-glass" href="{{ route('search') }}" :current="request()->routeIs('search')" wire:navigate>{{ __('Search') }}</flux:navlist.item>
                    @can('administer')
                        <flux:navlist.item icon="user-group" href="{{ route('admin.teams') }}" :current="request()->routeIs('admin.teams')" wire:navigate>{{ __('Teams') }}</flux:navlist.item>
                        <flux:navlist.item icon="users" href="{{ route('admin.users') }}" :current="request()->routeIs('admin.users')" wire:navigate>{{ __('Users') }}</flux:navlist.item>
                    @endcan
                </flux:navlist>
            </flux:sidebar>
        @endauth

        @auth
            {{-- Kept across wire:navigate page changes instead of being mounted again on every page. --}}
            @persist('command-palette')
                <livewire:command-palette />
            @endpersist
        @endauth

        <flux:main container>
            {{ $slot }}
        </flux:main>

        @persist('toast')
            <flux:toast />
            <x-celebration />
        @endpersist

        <x-file-preview />
        <x-keyboard-shortcuts />

        @fluxScripts
    </body>
</html>
