<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Models\AppVersion;

/**
 * GET app/version?platform=ios|android   (public, no login needed)
 * The app calls this on launch and when it comes back to the foreground.
 */
class AppVersionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $request->validate(['platform' => ['required', 'in:ios,android']]);

        return response()->json(['data' => AppVersion::forPlatform($request->query('platform'))->toArrayFlat()]);
    }
}