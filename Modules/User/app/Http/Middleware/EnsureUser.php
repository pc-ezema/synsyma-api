<?php

namespace Modules\User\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\Response;

class EnsureUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_active) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}