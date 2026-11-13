<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Public (no login): the one number shown on the admin sign-in page, new customer signups this week.
 * Counts only. No names, emails or ids leave the server. Cached for 10 minutes and throttled on the route.
 */
class PublicStatsController extends Controller
{
    public function loginStats(): JsonResponse
    {
        $data = Cache::remember('admin-login-stats', 600, function () {
            $model = (string) config('auth.providers.users.model');
            $tz = config('app.timezone');
            $today = CarbonImmutable::now($tz)->startOfDay();

            // Fourteen days of signups, so we can compare this week with the one before and draw the last 12 days.
            $days = [];
            for ($i = 13; $i >= 0; $i--) {
                $days[$today->subDays($i)->format('Y-m-d')] = 0;
            }
            $model::query()->where('created_at', '>=', $today->subDays(13)->toDateTimeString())->pluck('created_at')
                ->each(function ($t) use (&$days, $tz) {
                    $k = CarbonImmutable::parse($t, $tz)->format('Y-m-d');
                    if (isset($days[$k])) {
                        $days[$k]++;
                    }
                });

            $counts = array_values($days);
            $week = array_sum(array_slice($counts, 7));
            $previous = array_sum(array_slice($counts, 0, 7));

            return [
                'week' => $week,
                'previous' => $previous,
                'change' => $previous > 0 ? round((($week - $previous) / $previous) * 100, 1) : null,
                'series' => array_slice($counts, 2), // the last 12 days
            ];
        });

        return response()->json(['data' => $data]);
    }
}