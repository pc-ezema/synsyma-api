<?php

namespace Modules\Profile\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Authentication\Services\OtpService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    public function __construct(private OtpService $otp)
    {
    }

    public function show(Request $request): JsonResponse
    {
        Log::info($request->user());
        Log::info($request->user()->toApiArray());
        return response()->json(['data' => $request->user()->toApiArray()]);
    }

    /** Only the fields you send are changed. Send null (or "") for phone/address to clear them. */
    public function update(Request $request): JsonResponse
    {
        // 1) Normalize BEFORE validation so spaces/dashes/parens don't fail the regex.
        if ($request->has('phone') && is_string($request->input('phone'))) {
            $raw = trim($request->input('phone'));
            // Strip spaces, dashes, dots, parens — keep digits and a leading +
            $clean = preg_replace('/[\s\-\.\(\)]/', '', $raw);

            // Keep only a leading + if present; strip any other +
            if ($clean !== '' && !str_starts_with($clean, '+')) {
                $clean = str_replace('+', '', $clean);
            }

            $request->merge([
                'phone' => $clean === '' ? null : $clean,
            ]);
        }

        // 2) Validate with a worldwide pattern.
        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name'  => ['sometimes', 'required', 'string', 'max:100'],

            // E.164-friendly, worldwide:
            //   - optional leading +
            //   - 7 to 15 digits (shortest national number to longest E.164)
            //   - no leading zero (E.164 rule; local numbers with leading 0 still pass if they have 7-15 digits — but see note below)
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[1-9]\d{6,14}$/'],

            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ], [
            'phone.regex' => 'Enter a valid phone number (7–15 digits, optional leading +).',
        ]);

        foreach (['first_name', 'last_name', 'address'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        $user = $request->user();
        $user->fill($data)->save();

        return response()->json([
            'message' => 'Profile updated.',
            'data'    => $user->fresh()->toApiArray(),
        ]);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => [
                'required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120',
                'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000',
            ],
        ], [
            'photo.max' => 'The photo must be 5MB or smaller.',
            'photo.dimensions' => 'The photo must be at least 100px and no larger than 6000px.',
        ]);

        $user = $request->user();
        $old = $user->profile_photo_path;

        $path = $request->file('photo')->store("profile-photos/{$user->id}", 'public');
        $user->forceFill(['profile_photo_path' => $path])->save();

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return response()->json([
            'message' => 'Profile photo updated.',
            'data' => $user->toApiArray(),
        ]);
    }

    public function deletePhoto(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
            $user->forceFill(['profile_photo_path' => null])->save();
        }

        return response()->json([
            'message' => 'Profile photo removed.',
            'data' => $user->toApiArray(),
        ]);
    }

    /** Step 1: confirm the account email and send a code to it. Call again to resend. */
    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = $request->user();

        if (Str::lower(trim($data['email'])) !== Str::lower($user->email)) {
            throw ValidationException::withMessages(['email' => 'That is not the email on your account.']);
        }

        if ($wait = $this->otp->cooldown($user->email, OtpService::PASSWORD_CHANGE)) {
            return response()->json([
                'message' => "Please wait {$wait} seconds before asking for another code.",
                'data' => ['retry_after' => $wait],
            ], 429);
        }

        $this->otp->send($user->email, OtpService::PASSWORD_CHANGE, $user->first_name);

        return response()->json([
            'message' => 'We have sent a code to your email.',
            'data' => [
                'email' => $user->email,
                'otp_expires_in' => $this->otp->ttlMinutes() * 60,
                'resend_in' => (int) config('authentication.otp.resend_seconds', 60),
            ],
        ]);
    }

    /** Step 2: check the code and hand back a short-lived token for step 3. */
    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'otp' => ['required', 'digits:'.config('authentication.otp.length', 6)],
        ]);

        $user = $request->user();

        if (! $this->otp->verify($user->email, OtpService::PASSWORD_CHANGE, $data['otp'])) {
            throw ValidationException::withMessages(['otp' => 'Invalid or expired code.']);
        }

        return response()->json([
            'data' => [
                'change_token' => $this->otp->issueSessionToken($user->email, OtpService::PASSWORD_CHANGE_SESSION),
                'expires_in' => 15 * 60,
            ],
        ]);
    }

    /** Step 3: set the new password. */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'change_token' => ['required', 'string'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ]);

        $user = $request->user();

        // Check the token first without using it up, so wrong passwords can't be probed.
        if (! $this->otp->verify($user->email, OtpService::PASSWORD_CHANGE_SESSION, $data['change_token'], false)) {
            return response()->json([
                'message' => 'This session has expired. Please request a new code.',
                'code' => 'change_expired',
            ], 422);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Choose a password you have not used before.',
            ]);
        }

        $this->otp->forget($user->email, OtpService::PASSWORD_CHANGE_SESSION);

        $user->forceFill(['password' => $data['password']])->save();

        return response()->json(['message' => 'Password updated.']);
    }
}