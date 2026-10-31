<?php

namespace Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Admin\Models\Admin;

class EnsureAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user instanceof Admin || ! $user->is_active) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
