{{-- What the service worker shows when a page cannot be loaded: without a connection, or while the server is down (restart, update). It is cached once (often before logging in),
     so nothing here is personal and it carries every language: the one last used in Sprint is shown (see app.js). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="min-h-screen">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        <link rel="icon" href="{{ \App\Http\Controllers\ProgressiveWebAppController::ICON }}" type="image/png">
        <meta name="theme-color" content="{{ \App\Http\Controllers\ProgressiveWebAppController::THEME_COLOR }}" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="{{ \App\Http\Controllers\ProgressiveWebAppController::THEME_COLOR_DARK }}" media="(prefers-color-scheme: dark)">
        @vite('resources/css/app.css')
        @fluxAppearance
    </head>
    <body class="flex min-h-screen items-center justify-center bg-white p-6 dark:bg-zinc-800">
        <div class="max-w-sm space-y-4 text-center">
            <img src="{{ \App\Http\Controllers\ProgressiveWebAppController::ICON }}" alt="" class="mx-auto size-12">

            @foreach (\App\Services\LocaleService::codes() as $locale)
                <div data-locale="{{ $locale }}" lang="{{ $locale }}" class="space-y-4" @if ($locale !== app()->getLocale()) hidden @endif>
                    <flux:heading size="lg">{{ __('Sprint is not reachable', [], $locale) }}</flux:heading>
                    <flux:text>{{ __('Check your internet connection. If it is fine, the server is probably restarting or being updated. :app loads again by itself as soon as it is reachable.', ['app' => config('app.name')], $locale) }}</flux:text>
                    <flux:text data-stopped hidden>{{ __('Sprint is not checking any more. Try again when the server is running.', [], $locale) }}</flux:text>
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

                // Asks the app itself (/up, which only the app answers, with its header) and loads the page again once it does.
                // Every 15 seconds for 5 minutes, then it stops (the server may have been shut down on purpose); coming back to the
                // page or the network coming back asks once more.
                const RETRY_EVERY = 15000
                const GIVE_UP_AFTER = 5 * 60 * 1000
                const startedAt = Date.now()
                let checking = false

                const check = async () => {
                    if (checking || document.visibilityState === 'hidden') {
                        return
                    }

                    checking = true

                    try {
                        const response = await fetch('/up', { cache: 'no-store' })

                        if (response.ok && response.headers.has('{{ \App\Http\Middleware\MarkAppResponse::HEADER }}')) {
                            location.reload()
                        }
                    } catch {}

                    checking = false
                }

                const timer = setInterval(() => {
                    if (Date.now() - startedAt >= GIVE_UP_AFTER) {
                        clearInterval(timer)
                        document.querySelectorAll('[data-locale]:not([hidden]) [data-stopped]').forEach((note) => note.hidden = false)

                        return
                    }

                    check()
                }, RETRY_EVERY)
                window.addEventListener('online', check)
                document.addEventListener('visibilitychange', check)
            })()
        </script>
    </body>
</html>
