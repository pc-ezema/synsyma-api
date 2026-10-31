<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Admin\Models\Admin;
use Modules\Admin\Transformers\AdminResource;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $admin = Admin::where('email', $data['email'])->first();

        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            return response()->json(['message' => 'Email or password is incorrect.'], 401);
        }

        if (! $admin->is_active) {
            return response()->json(['message' => 'This account has been disabled.'], 403);
        }

        $admin->forceFill(['last_login_at' => now()])->save();

        return $this->issueTokens($admin, $data['device_name'] ?? 'admin-dashboard');
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $admin = Admin::where('refresh_token', hash('sha256', $data['refresh_token']))
            ->where('refresh_token_expires_at', '>', now())
            ->first();

        if (! $admin || ! $admin->is_active) {
            return response()->json(['message' => 'Session expired. Please sign in again.'], 401);
        }

        return $this->issueTokens($admin, $data['device_name'] ?? 'admin-dashboard');
    }

    public function logout(Request $request): JsonResponse
    {
        $admin = $request->user();

        $admin->tokens()->delete();
        $admin->forceFill([
            'refresh_token' => null,
            'refresh_token_expires_at' => null,
        ])->save();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Issues a fresh access token and a rotated refresh token.
     * Only one session per admin is active at a time.
     */
    private function issueTokens(Admin $admin, string $device): JsonResponse
    {
        $accessMinutes = (int) config('admin.access_token_ttl_minutes', 60);
        $refreshDays = (int) config('admin.refresh_token_ttl_days', 30);

        $admin->tokens()->delete();

        $plainRefresh = Str::random(80);
        $refreshExpiresAt = now()->addDays($refreshDays);

        // Only the hash is stored; the plain value is returned once.
        $admin->forceFill([
            'refresh_token' => hash('sha256', $plainRefresh),
            'refresh_token_expires_at' => $refreshExpiresAt,
        ])->save();

        $accessToken = $admin->createToken($device, ['*'], now()->addMinutes($accessMinutes));

        return response()->json([
            'data' => array_merge([
                'token' => $accessToken->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => $accessMinutes * 60,
                'refresh_token' => $plainRefresh,
                'refresh_token_expires_at' => $refreshExpiresAt->toIso8601String(),
            ], (new AdminResource($admin))->resolve()),
        ]);
    }
}
