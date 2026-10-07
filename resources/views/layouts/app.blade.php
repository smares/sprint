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
                <flux:brand href="{{ route('projects.index') }}" name="{{ config('app.name') }}" />

                <flux:navbar class="ms-4">
                    <flux:navbar.item href="{{ route('projects.index') }}" :current="request()->routeIs('projects.*')" wire:navigate>Projekte</flux:navbar.item>
                    <flux:navbar.item href="{{ route('tasks.mine') }}" :current="request()->routeIs('tasks.mine')" wire:navigate>Meine Aufgaben</flux:navbar.item>
                </flux:navbar>

                <flux:spacer />

                <flux:dropdown position="bottom" align="end">
                    <flux:profile :name="auth()->user()->name" initials="{{ auth()->user()->initials() }}" />
                    <flux:menu>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle">Abmelden</flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </flux:header>
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
