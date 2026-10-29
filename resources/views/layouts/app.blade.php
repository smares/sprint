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
        {{-- The browser's bar (and the installed app's) in the color of the header; app.js follows the appearance chosen in the menu --}}
        <meta name="theme-color" content="{{ \App\Http\Controllers\ProgressiveWebAppController::THEME_COLOR }}" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="{{ \App\Http\Controllers\ProgressiveWebAppController::THEME_COLOR_DARK }}" media="(prefers-color-scheme: dark)">
        <meta name="sprint-build" content="{{ app(\App\Services\AppBuild::class)->id() }}" data-header="{{ \App\Http\Middleware\MarkAppResponse::BUILD_HEADER }}">
        @auth
            <meta name="sprint-interface" content="{{ route('interface.update', absolute: false) }}">
            <script>
                {{-- The appearance the person chose on any of their devices, before Flux reads it and the page is painted (on a full page load) --}}
                (() => { try { const appearance = @js(auth()->user()->interfaceState()['appearance'] ?? null), adopted = localStorage.getItem('sprint.interface-adopted'); if (appearance === 'system' || (! appearance && adopted)) { localStorage.removeItem('flux.appearance') } else if (appearance) { localStorage.setItem('flux.appearance', appearance) } } catch {} })()
            </script>
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
        {{-- The first thing the keyboard reaches: jumps over the sidebar to the content; visible only while focused --}}
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-zinc-900 focus:shadow-lg focus:outline-2 focus:outline-offset-2 dark:focus:bg-zinc-800 dark:focus:text-white">{{ __('Skip to content') }}</a>

        @auth
            {{-- The state kept on the server, before the sidebar (Flux) reads whether it was collapsed; this runs again on every
                 page change, so a change made on another device arrives with the next page --}}
            <script>
                {{-- A change of this device the server has not confirmed yet is newer than what this page was rendered with --}}
                {{-- ... and so is a state older than the one this device has seen (a page that comes back from the history), or one of another person --}}
                (() => { const served = @js((object) auth()->user()->interfaceState()), current = window.sprintInterface; if (! current || window.sprintInterfaceUser !== @js(auth()->id()) || (! window.sprintHasUnsentChanges?.() && (served.rev ?? 0) >= (current.rev ?? 0))) { window.sprintInterface = served; window.sprintInterfaceUser = @js(auth()->id()) } })();
                try { window.sprintSeeding = true; if ('sidebar_collapsed' in window.sprintInterface) { localStorage.setItem('flux-sidebar-collapsed-desktop', JSON.stringify(window.sprintInterface.sidebar_collapsed)) } else if (localStorage.getItem('sprint.interface-adopted')) { localStorage.removeItem('flux-sidebar-collapsed-desktop') } } catch {} window.sprintSeeding = false
            </script>

            {{-- The sidebar is the navigation on every screen: the main pages, the projects with the views of the current one
                 and the person's menu. On large screens it can be collapsed to icons (remembered for the person); on phones it
                 slides in from the header's menu button. --}}
            <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <flux:sidebar.header>
                    <flux:sidebar.brand :href="route('projects.index')" :name="config('app.name')" wire:navigate>
                        <x-slot name="logo" class="size-6!">
                            <x-app-logo class="size-6" />
                        </x-slot>
                    </flux:sidebar.brand>
                    <flux:sidebar.collapse class="max-lg:hidden in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-me-2" />
                    {{-- On phones and tablets the sidebar lies over the page and this only closes it. Hidden by its wrapper: the tooltip
                         around the button stays in the header's row otherwise and pushes the collapse button away from the edge. --}}
                    <div class="lg:hidden">
                        <flux:sidebar.toggle icon="x-mark" variant="ghost" :aria-label="__('Close menu')" :tooltip="__('Close menu')" />
                    </div>
                </flux:sidebar.header>

                <flux:modal.trigger name="command-palette" shortcut="cmd.k">
                    @php
                        // The shortcut as the platform's: ⌘K on Apple devices, Ctrl K elsewhere (the server's text stands until Alpine runs); not on phones
                        $searchShortcut = new \Illuminate\Support\HtmlString('<span class="max-lg:hidden" x-data x-text="/Mac|iPhone|iPad/.test(navigator.platform) ? \'⌘K\' : '.e(json_encode(__('Ctrl K'))).'">'.e(__('Ctrl K')).'</span>');
                    @endphp
                    <flux:sidebar.search :placeholder="__('Search')" :kbd="$searchShortcut" tooltip:kbd="" :tooltip="__('Search or jump to…')" :aria-label="__('Search or jump to…')" />
                </flux:modal.trigger>

                <flux:modal.trigger name="command-palette" shortcut="ctrl.k">
                    <span class="hidden"></span>
                </flux:modal.trigger>

                <flux:sidebar.nav>
                    <flux:sidebar.item icon="folder" :href="route('projects.index')" :current="request()->routeIs('projects.index')" wire:navigate>{{ __('Projects') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="check-circle" :href="route(auth()->user()->mineTasksRoute())" wire:navigate :current="false">{{ __('My tasks') }}</flux:sidebar.item>
                    @if ($mineView = \App\Enums\MineView::forRoute(request()->route()?->getName()))
                        <x-sidebar-views :views="\App\Enums\MineView::links($mineView)" :label="__('My tasks')" />
                    @endif
                    <livewire:notification-bell />
                    <flux:sidebar.item icon="calendar-date-range" :href="route('workload')" :current="request()->routeIs('workload')" wire:navigate>{{ __('Workload') }}</flux:sidebar.item>
                    @can('administer')
                        <flux:sidebar.item icon="user-group" :href="route('admin.teams')" :current="request()->routeIs('admin.teams')" wire:navigate>{{ __('Teams') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="users" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>{{ __('Users') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="key" :href="route('admin.sso')" :current="request()->routeIs('admin.sso')" wire:navigate>{{ __('Single sign-on') }}</flux:sidebar.item>
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
                 A dot on the menu button tells that the inbox has something unread. It stays at the top while the page scrolls, so
                 the menu and the search are within reach wherever one is. --}}
            <flux:header sticky class="border-b border-zinc-200 bg-white lg:hidden dark:border-zinc-700 dark:bg-zinc-900">
                {{-- aria-expanded follows the sidebar, which Flux opens and closes with attributes on itself --}}
                <div class="relative" x-data="{ open: false }" x-init="const sidebar = document.querySelector('ui-sidebar'); const sync = () => open = sidebar.hasAttribute('data-flux-sidebar-on-mobile') && ! sidebar.hasAttribute('data-flux-sidebar-collapsed-mobile'); sync(); new MutationObserver(sync).observe(sidebar, { attributes: true })">
                    <flux:sidebar.toggle icon="bars-2" inset="left" aria-label="{{ __('Menu') }}" x-bind:aria-expanded="open" />
                    <livewire:notification-bell variant="dot" :announces="false" />
                </div>

                <x-app-brand class="ms-2" />

                <flux:spacer />

                <flux:modal.trigger name="command-palette">
                    {{-- No tooltip: after a tap it would stay over the palette --}}
                    <flux:button variant="ghost" icon="magnifying-glass" aria-label="{{ __('Search or jump to…') }}" />
                </flux:modal.trigger>
            </flux:header>
        @endauth

        @auth
            {{-- Kept across wire:navigate page changes instead of being mounted again on every page. --}}
            @persist('command-palette')
                <livewire:command-palette />
            @endpersist

            {{-- Not kept: the open task is part of the address of the page it was opened on --}}
            <livewire:task-flyout />
        @endauth

        {{-- isolate: what the page stacks with z-index (sticky titles, shadows of scrolling areas, the bar of a selection) stays below the sticky header of phones --}}
        {{-- data-guest: without the sidebar (login, password reset) the content stands in the middle of wide windows, see app.css --}}
        <flux:main container id="main-content" role="main" tabindex="-1" class="isolate focus:outline-none" :data-guest="auth()->guest()">
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

        {{-- A page loaded before a deployment: its scripts may no longer fit the server. The person reloads when it suits them: something typed would be lost otherwise. At the top, so it never covers the selection bar or the offline notice --}}
        @persist('update-available')
            <div x-data="{ outdated: false }" x-on:sprint-outdated.window="outdated = true" x-show="outdated" x-cloak role="status" class="pointer-events-none fixed inset-x-0 top-3 z-50 flex justify-center px-4">
                <div class="pointer-events-auto flex items-center gap-3 rounded-full bg-zinc-800 py-2 ps-4 pe-3 text-sm text-white shadow-lg dark:bg-zinc-100 dark:text-zinc-900">
                    <flux:icon.arrow-path variant="micro" />
                    {{ __('A new version of :app is available.', ['app' => config('app.name')]) }}
                    <flux:button size="sm" variant="primary" x-on:click="window.location.reload()">{{ __('Reload') }}</flux:button>
                </div>
            </div>
        @endpersist

        <x-file-preview />
        <x-keyboard-shortcuts />

        @fluxScripts
    </body>
</html>
