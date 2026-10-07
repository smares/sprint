<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="min-h-screen">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ isset($title) ? $title.' – '.config('app.name') : config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @auth
            <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" aria-label="Menü" />

                <flux:brand href="{{ route('projects.index') }}" name="{{ config('app.name') }}" class="max-lg:hidden" />

                <flux:navbar class="ms-4 max-lg:hidden">
                    <flux:navbar.item href="{{ route('projects.index') }}" :current="request()->routeIs('projects.*')" wire:navigate>Projekte</flux:navbar.item>
                    <flux:navbar.item href="{{ route('tasks.mine') }}" :current="request()->routeIs('tasks.mine')" wire:navigate>Meine Aufgaben</flux:navbar.item>
                    @can('administer')
                        <flux:navbar.item href="{{ route('admin.teams') }}" :current="request()->routeIs('admin.teams')" wire:navigate>Teams</flux:navbar.item>
                        <flux:navbar.item href="{{ route('admin.users') }}" :current="request()->routeIs('admin.users')" wire:navigate>Benutzer</flux:navbar.item>
                    @endcan
                </flux:navbar>

                <flux:spacer />

                <form action="{{ route('search') }}" method="GET" class="me-2 hidden sm:block" role="search">
                    <flux:input name="q" type="search" size="sm" icon="magnifying-glass" placeholder="Suchen …" aria-label="Suchen" />
                </form>

                <flux:button href="{{ route('search') }}" wire:navigate variant="ghost" icon="magnifying-glass" aria-label="Suchen" class="sm:hidden" />

                <livewire:notification-bell />

                <flux:dropdown position="bottom" align="end">
                    <flux:profile :name="auth()->user()->name" initials="{{ auth()->user()->initials() }}" class="max-sm:[&>span.truncate]:hidden" />
                    <flux:menu>
                        <div class="px-2 py-1.5">
                            <flux:text size="sm" class="mb-1.5">Darstellung</flux:text>
                            <flux:radio.group x-data variant="segmented" x-model="$flux.appearance" size="sm">
                                <flux:radio value="light" icon="sun" aria-label="Hell" />
                                <flux:radio value="dark" icon="moon" aria-label="Dunkel" />
                                <flux:radio value="system" icon="computer-desktop" aria-label="System" />
                            </flux:radio.group>
                        </div>
                        <flux:menu.separator />
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle">Abmelden</flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </flux:header>

            <flux:sidebar stashable sticky class="border-e border-zinc-200 bg-zinc-50 lg:hidden dark:border-zinc-700 dark:bg-zinc-900">
                <flux:sidebar.toggle class="lg:hidden" icon="x-mark" aria-label="Menü schließen" />

                <flux:brand href="{{ route('projects.index') }}" name="{{ config('app.name') }}" class="px-2" />

                <flux:navlist variant="outline">
                    <flux:navlist.item icon="folder" href="{{ route('projects.index') }}" :current="request()->routeIs('projects.*')" wire:navigate>Projekte</flux:navlist.item>
                    <flux:navlist.item icon="check-circle" href="{{ route('tasks.mine') }}" :current="request()->routeIs('tasks.mine')" wire:navigate>Meine Aufgaben</flux:navlist.item>
                    <flux:navlist.item icon="bell" href="{{ route('inbox') }}" :current="request()->routeIs('inbox')" wire:navigate>Posteingang</flux:navlist.item>
                    <flux:navlist.item icon="magnifying-glass" href="{{ route('search') }}" :current="request()->routeIs('search')" wire:navigate>Suche</flux:navlist.item>
                    @can('administer')
                        <flux:navlist.item icon="user-group" href="{{ route('admin.teams') }}" :current="request()->routeIs('admin.teams')" wire:navigate>Teams</flux:navlist.item>
                        <flux:navlist.item icon="users" href="{{ route('admin.users') }}" :current="request()->routeIs('admin.users')" wire:navigate>Benutzer</flux:navlist.item>
                    @endcan
                </flux:navlist>
            </flux:sidebar>
        @endauth

        <flux:main container>
            {{ $slot }}
        </flux:main>

        @persist('toast')
            <flux:toast />
        @endpersist

        @fluxScripts
    </body>
</html>
