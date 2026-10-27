<?php

namespace App\Http\Middleware;

use App\Services\AppBuild;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every answer of the app (pages, errors of the app, even "not found" for an unknown address) carries this header. The service
 * worker uses it to tell the app's own answers from those of a proxy in front of it, such as the "404 page not found" of
 * Traefik while the container is down. It also carries the build, which tells a page loaded before a deployment that it is out of date.
 */
class MarkAppResponse
{
    public const string HEADER = 'X-Sprint-App';

    public const string BUILD_HEADER = 'X-Sprint-Build';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set(self::HEADER, '1');
        $response->headers->set(self::BUILD_HEADER, app(AppBuild::class)->id());

        return $response;
    }
}
