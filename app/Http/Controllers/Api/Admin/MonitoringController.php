<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * État de santé de la plateforme pour la console admin.
 */
class MonitoringController extends Controller
{
    public function index(MonitoringService $monitoring): JsonResponse
    {
        $results = $monitoring->run();
        $lastRun = Cache::get('monitor:last_run');

        return response()->json([
            'status' => 'success',
            'data' => [
                'overall' => $monitoring->overall($results),
                'checks' => array_map(fn ($r) => $r->toArray(), $results),
                'checked_at' => now()->toIso8601String(),
                // Dernier passage du scheduler : s'il est ancien, `schedule:work` est arrêté.
                'scheduler_last_run' => $lastRun,
                'scheduler_stale' => ! $lastRun || Carbon::parse($lastRun)->diffInMinutes(now(), true) > 5,
            ],
        ]);
    }
}
