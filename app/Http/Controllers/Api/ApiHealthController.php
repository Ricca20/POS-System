<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Public health probe used by Electron's server-manager (R2.3) to detect
 * when the bundled `php artisan serve` process is ready to accept SPA
 * traffic. Intentionally not protected by Sanctum so it can be polled
 * before the user has logged in.
 */
class ApiHealthController extends Controller
{
    /**
     * Return a fixed success envelope plus an ISO8601-Z timestamp so the
     * server-manager can verify the response was freshly generated.
     */
    public function ping(): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'ok',
                'timestamp' => now()->toIso8601ZuluString(),
            ],
        ]);
    }
}
