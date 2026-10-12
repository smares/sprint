<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="min-h-screen">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ isset($title) ? $title.' – '.config('app.name') : config('app.name') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="{{ route('pwa.manifest', absolute: false) }}">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
        <meta name="theme-color" content="#18181b">
        @auth
            <meta name="sprint-push-check" content="{{ route('push.forget-others', absolute: false) }}" data-user="{{ auth()->id() }}">
        @endauth
        @if ($realtime = app(\App\Services\RealtimeService::class)->clientConfig())
            <script>window.sprintRealtime = @js($realtime)</script>
            @vite(['resources/css/app.css', 'resources/js/realtime.js', 'resources/js/app.js'])
        @else
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-zinc-50 dark:bg-zinc-900">
        @auth
            {{-- The sidebar is the navigation on every screen: the main pages, the projects with the views of the current one
                 and the person's menu. On large screens it can be collapsed to icons (remembered in the browser); on phones it
                 slides in from the header's menu button. --}}
            <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <flux:sidebar.header>
                    <flux:sidebar.brand :href="route('projects.index')" :name="config('app.name')" wire:navigate>
                        <x-slot name="logo" class="size-6!">
                            <x-app-logo class="size-6" />
                        </x-slot>
                    </flux:sidebar.brand>
                    <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-me-2" />
                </flux:sidebar.header>

                <flux:modal.trigger name="command-palette" shortcut="cmd.k">
                    <flux:sidebar.search :placeholder="__('Search')" kbd="⌘K" :tooltip="__('Search or jump to…')" :aria-label="__('Open command palette')" />
                </flux:modal.trigger>

                <flux:modal.trigger name="command-palette" shortcut="ctrl.k">
                    <span class="hidden"></span>
                </flux:modal.trigger>

                <flux:sidebar.nav>
                    <flux:sidebar.item icon="folder" :href="route('projects.index')" :current="request()->routeIs('projects.index')" wire:navigate>{{ __('Projects') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="check-circle" :href="route('tasks.mine')" :current="request()->routeIs('tasks.mine')" wire:navigate>{{ __('My tasks') }}</flux:sidebar.item>
                    <livewire:notification-bell />
                    <flux:sidebar.item icon="calendar-date-range" :href="route('workload')" :current="request()->routeIs('workload')" wire:navigate>{{ __('Workload') }}</flux:sidebar.item>
                    @can('administer')
                        <flux:sidebar.item icon="user-group" :href="route('admin.teams')" :current="request()->routeIs('admin.teams')" wire:navigate>{{ __('Teams') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="users" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>{{ __('Users') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.nav>

                <livewire:sidebar-projects />

                <flux:sidebar.spacer />

                <flux:dropdown position="top" align="start">
                    <flux:sidebar.profile circle :name="auth()->user()->name" :initials="auth()->user()->initials()" :avatar="auth()->user()->avatarUrl()" :avatar:color="auth()->user()->avatarColor()" />
                    <x-user-menu />
                </flux:dropdown>
            </flux:sidebar>

            {{-- On phones only what is needed at once: the menu (the sidebar, the same as on large screens), the name and the search.
                 A dot on the menu button tells that the inbox has something unread. --}}
            <flux:header class="border-b border-zinc-200 bg-white lg:hidden dark:border-zinc-700 dark:bg-zinc-900">
                <div class="relative">
                    <flux:sidebar.toggle icon="bars-2" inset="left" aria-label="{{ __('Menu') }}" />
                    <livewire:notification-bell variant="dot" :announces="false" />
                </div>

                <x-app-brand class="ms-2" />

                <flux:spacer />

                <flux:modal.trigger name="command-palette">
                    <flux:button variant="ghost" icon="magnifying-glass" aria-label="{{ __('Find') }}" tooltip="{{ __('Find') }}" />
                </flux:modal.trigger>
            </flux:header>
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

        {{-- Without a connection nothing can be saved; pages that are not loaded yet show the offline page of the service worker --}}
        @persist('connection')
            <div x-data="{ online: navigator.onLine }" x-on:online.window="online = true" x-on:offline.window="online = false" x-show="! online" style="display: none" role="status" class="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex justify-center px-4">
                <div class="flex items-center gap-2 rounded-full bg-zinc-800 px-4 py-2 text-sm text-white shadow-lg dark:bg-zinc-100 dark:text-zinc-900">
                    <flux:icon.signal-slash variant="micro" />
                    {{ __('No connection. Changes cannot be saved right now.') }}
                </div>
            </div>
        @endpersist

        <x-file-preview />
        <x-keyboard-shortcuts />

        @fluxScripts
    </body>
</html>
