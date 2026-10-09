{{-- What the service worker shows when a page cannot be loaded without a connection. It is cached once (often before logging in),
     so nothing here is personal and it carries every language: the one last used in Sprint is shown (see app.js). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="min-h-screen">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        <link rel="icon" href="/icon-192.png" type="image/png">
        <meta name="theme-color" content="#18181b">
        @vite('resources/css/app.css')
        @fluxAppearance
    </head>
    <body class="flex min-h-screen items-center justify-center bg-white p-6 dark:bg-zinc-800">
        <div class="max-w-sm space-y-4 text-center">
            <img src="/icon-192.png" alt="" class="mx-auto size-12">

            @foreach (\App\Services\LocaleService::codes() as $locale)
                <div data-locale="{{ $locale }}" lang="{{ $locale }}" class="space-y-4" @if ($locale !== app()->getLocale()) hidden @endif>
                    <flux:heading size="lg">{{ __('No connection', [], $locale) }}</flux:heading>
                    <flux:text>{{ __(':app needs an internet connection. As soon as you are back online, the page loads again.', ['app' => config('app.name')], $locale) }}</flux:text>
                    <flux:button variant="primary" onclick="location.reload()">{{ __('Try again', [], $locale) }}</flux:button>
                </div>
            @endforeach
        </div>

        <script>
            (() => {
                let locale = null

                try {
                    locale = localStorage.getItem('sprint.locale')
                } catch {}

                const chosen = document.querySelector(`[data-locale="${locale}"]`)

                if (chosen) {
                    document.querySelectorAll('[data-locale]').forEach((block) => block.hidden = block !== chosen)
                    document.documentElement.lang = locale
                }

                document.title = document.querySelector('[data-locale]:not([hidden]) [data-flux-heading]').textContent + ' – ' + document.title
                window.addEventListener('online', () => location.reload())
            })()
        </script>
    </body>
</html>
