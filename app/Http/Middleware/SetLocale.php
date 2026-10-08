<?php

namespace App\Http\Middleware;

use App\Services\LocaleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed-in people get the language of their profile; visitors the one they picked on the login page
 * or, failing that, the one their browser asks for.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;

        if (! LocaleService::isSupported($locale)) {
            $stored = $request->hasSession() ? $request->session()->get('locale') : null;
            $locale = LocaleService::isSupported($stored) ? $stored : (LocaleService::fromHeader($request->header('Accept-Language')) ?? config('app.locale'));
        }

        LocaleService::apply($locale);

        return $next($request);
    }
}
