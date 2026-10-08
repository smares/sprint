<?php

namespace App\Http\Controllers;

use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

/**
 * For uptime monitors: 200 while the installation works, 503 when something essential is broken.
 * Only the status per check is shown here; the details are for `php artisan sprint:health`.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheckService $health): JsonResponse
    {
        $checks = $health->run();
        $overall = $health->overall($checks);

        return response()->json([
            'status' => $overall,
            'checks' => array_map(fn (array $check) => $check['status'], $checks),
        ], $overall === 'down' ? 503 : 200, ['Cache-Control' => 'no-store']);
    }
}
