<?php

namespace Modules\User\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Admin\Models\AppVersion;

/**
 * Safety net for breaking API changes: a build older than min_version gets 426 Upgrade Required.
 * Requests with no X-App-Version header (admin web, webhooks, old builds) pass through untouched.
 */
class EnforceMinAppVersion
{
    public function handle(Request $request, Closure $next)
    {
        $version = (string) $request->header('X-App-Version', '');
        $platform = strtolower((string) $request->header('X-App-Platform', ''));

        if ($version !== '' && in_array($platform, AppVersion::PLATFORMS, true)) {
            $v = AppVersion::forPlatform($platform);
            if (AppVersion::compare($version, $v->min_version) < 0) {
                return response()->json([
                    'message' => $v->message ?: 'Please update the app to keep using Synsyma.',
                    'data' => ['update_required' => true, 'store_url' => $v->store_url, 'min_version' => $v->min_version],
                ], 426);
            }
        }

        return $next($request);
    }
}