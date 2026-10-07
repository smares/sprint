<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only real API tokens of active people through; session cookies do not count.
 */
class EnsureMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $user->isActive() && $user->currentAccessToken() instanceof PersonalAccessToken,
            401,
            'Unauthenticated.',
        );

        return $next($request);
    }
}
