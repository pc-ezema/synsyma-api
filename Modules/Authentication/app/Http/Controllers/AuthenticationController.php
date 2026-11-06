<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Services\OtpService;
use Modules\Profile\Support\PasswordChangeNotifier;
use Modules\User\Models\User;

class AuthenticationController extends Controller
{
    public function __construct(private OtpService $otp)
    {
    }

    public function register(Request $request): JsonResponse
    {
        $this->normalize($request);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', Password::min(8)->letters()->numbers()],
            'terms' => ['required', 'accepted'],
        ], [
            'terms.required' => 'Please accept the Terms & Conditions.',
            'terms.accepted' => 'Please accept the Terms & Conditions.',
        ]);

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'terms_accepted_at' => now(),
            'terms_version' => config('authentication.terms_version', '1.0'),
        ]);

        $this->otp->cooldown($user->email, OtpService::EMAIL_VERIFICATION);
        $this->otp->send($user->email, OtpService::EMAIL_VERIFICATION, $user->first_name);

        return response()->json([
            'message' => 'Account created. Enter the code we emailed you to verify your email.',
            'data' => $this->otpInfo($user->email),
        ], 201);
    }


    public function login(Request $request): JsonResponse
    {
        $this->normalize($request);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Email or password is incorrect.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This account has been disabled.'], 403);
        }

        if (! $user->email_verified_at) {
            if ($this->otp->cooldown($user->email, OtpService::EMAIL_VERIFICATION) === 0) {
                $this->otp->send($user->email, OtpService::EMAIL_VERIFICATION);
            }

            return response()->json([
                'message' => 'Please verify your email to continue.',
                'code' => 'email_not_verified',
                'data' => $this->otpInfo($user->email),
            ], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->issueTokens($user, $data['device_name'] ?? 'mobile-app');
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $this->normalize($request);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->is_active
            || ! $this->otp->verify($data['email'], OtpService::EMAIL_VERIFICATION, $data['otp'])) {
            $this->invalidOtp();
        }

        $user->forceFill(['email_verified_at' => now(), 'last_login_at' => now()])->save();

        // Verified people go straight in, so the app can continue without a login screen.
        return $this->issueTokens($user, $data['device_name'] ?? 'mobile-app');
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $this->normalize($request);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'purpose' => ['required', Rule::in([OtpService::EMAIL_VERIFICATION, OtpService::PASSWORD_RESET])],
        ]);

        if ($wait = $this->otp->cooldown($data['email'], $data['purpose'])) {
            return $this->tooSoon($wait);
        }

        $user = User::where('email', $data['email'])->first();

        $eligible = $user && $user->is_active && (
            $data['purpose'] === OtpService::PASSWORD_RESET || ! $user->email_verified_at
        );

        if ($eligible) {
            $this->otp->send($data['email'], $data['purpose'], $user->first_name);
        }

        // Same answer whether or not the account exists.
        return response()->json([
            'message' => 'If the account exists, a new code has been sent.',
            'data' => $this->otpInfo($data['email']),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $this->normalize($request);

        $data = $request->validate(['email' => ['required', 'email']]);

        if ($wait = $this->otp->cooldown($data['email'], OtpService::PASSWORD_RESET)) {
            return $this->tooSoon($wait);
        }

        $user = User::where('email', $data['email'])->first();

        if ($user && $user->is_active) {
            $this->otp->send($data['email'], OtpService::PASSWORD_RESET, $user->first_name);
        }

        return response()->json([
            'message' => 'If an account exists for that email, we have sent a reset code.',
            'data' => $this->otpInfo($data['email']),
        ]);
    }

    public function verifyResetCode(Request $request): JsonResponse
    {
        $this->normalize($request);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:'.config('authentication.otp.length', 6)],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->is_active
            || ! $this->otp->verify($data['email'], OtpService::PASSWORD_RESET, $data['otp'])) {
            $this->invalidOtp();
        }

        return response()->json([
            'data' => [
                'reset_token' => $this->otp->issueResetToken($data['email']),
                'expires_in' => 15 * 60,
            ],
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $this->normalize($request);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'reset_token' => ['required', 'string'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Check the token first (without using it up), so this can't be used to guess passwords.
        if (! $user || ! $user->is_active
            || ! $this->otp->verify($data['email'], OtpService::RESET_SESSION, $data['reset_token'], false)) {
            return response()->json([
                'message' => 'This reset session has expired. Please start again.',
                'code' => 'reset_expired',
            ], 422);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Choose a password you have not used before.',
            ]);
        }

        $this->otp->forget($data['email'], OtpService::RESET_SESSION);

        $user->forceFill([
            'password' => $data['password'],
            'refresh_token' => null,
            'refresh_token_expires_at' => null,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $user->tokens()->delete(); // sign out everywhere

        PasswordChangeNotifier::send($user, $request, viaReset: true, signedOutOthers: true);

        return response()->json(['message' => 'Password updated. Please sign in with your new password.']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::where('refresh_token', hash('sha256', $data['refresh_token']))
            ->where('refresh_token_expires_at', '>', now())
            ->first();

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Session expired. Please sign in again.'], 401);
        }

        return $this->issueTokens($user, $data['device_name'] ?? 'mobile-app');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->tokens()->delete();
        $user->forceFill(['refresh_token' => null, 'refresh_token_expires_at' => null])->save();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->toApiArray()]);
    }

    /**
     * Issues a fresh access token and a rotated refresh token.
     * Only one session per user is active at a time.
     */
    private function issueTokens(User $user, string $device): JsonResponse
    {
        $accessMinutes = (int) config('authentication.access_token_ttl_minutes', 60);
        $refreshDays = (int) config('authentication.refresh_token_ttl_days', 30);

        $user->tokens()->delete();

        $plainRefresh = Str::random(80);
        $refreshExpiresAt = now()->addDays($refreshDays);

        // Only the hash is stored; the plain value is returned once.
        $user->forceFill([
            'refresh_token' => hash('sha256', $plainRefresh),
            'refresh_token_expires_at' => $refreshExpiresAt,
        ])->save();

        $accessToken = $user->createToken($device, ['*'], now()->addMinutes($accessMinutes));

        return response()->json([
            'data' => array_merge([
                'token' => $accessToken->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => $accessMinutes * 60,
                'refresh_token' => $plainRefresh,
                'refresh_token_expires_at' => $refreshExpiresAt->toIso8601String(),
            ], $user->toApiArray()),
        ]);
    }

    private function normalize(Request $request): void
    {
        if ($request->has('email')) {
            $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        }
    }

    private function otpInfo(string $email): array
    {
        return [
            'email' => $email,
            'otp_expires_in' => $this->otp->ttlMinutes() * 60,
            'resend_in' => (int) config('authentication.otp.resend_seconds', 60),
        ];
    }

    private function tooSoon(int $seconds): JsonResponse
    {
        return response()->json([
            'message' => "Please wait {$seconds} seconds before asking for another code.",
            'data' => ['retry_after' => $seconds],
        ], 429);
    }

    private function invalidOtp(): never
    {
        throw ValidationException::withMessages(['otp' => 'Invalid or expired code.']);
    }
}
