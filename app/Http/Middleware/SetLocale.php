<?php

namespace App\Http\Middleware;

use App\Services\Locale;
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

        if (! Locale::isSupported($locale)) {
            $stored = $request->hasSession() ? $request->session()->get('locale') : null;
            $locale = Locale::isSupported($stored) ? $stored : (Locale::fromHeader($request->header('Accept-Language')) ?? config('app.locale'));
        }

        Locale::apply($locale);

        return $next($request);
    }
}
