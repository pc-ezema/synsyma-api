<?php

namespace Modules\Authentication\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Authentication\Models\Otp;
use Throwable;

class OtpService
{
    public const EMAIL_VERIFICATION = 'email_verification';
    public const PASSWORD_RESET = 'password_reset';
    public const RESET_SESSION = 'password_reset_session';
    public const PASSWORD_CHANGE = 'password_change';
    public const PASSWORD_CHANGE_SESSION = 'password_change_session';

    public function ttlMinutes(): int
    {
        return (int) config('authentication.otp.ttl_minutes', 10);
    }

    /**
     * Returns 0 and starts the resend cooldown, or the seconds still to wait.
     * Tracked per email whether or not an account exists, so it leaks nothing.
     */
    public function cooldown(string $email, string $purpose): int
    {
        $key = "otp-cooldown:{$purpose}:{$email}";

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return RateLimiter::availableIn($key);
        }

        RateLimiter::hit($key, (int) config('authentication.otp.resend_seconds', 60));

        return 0;
    }

    /** 
     * Send an email with a code to verify an email or reset a password.
     */
    public function send(string $email, string $purpose, ?string $name = null): void
    {
        $length = (int) config('authentication.otp.length', 6);
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        Otp::updateOrCreate(
            ['email' => $email, 'purpose' => $purpose],
            [
                'code' => $this->hash($email, $purpose, $code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
            ],
        );

        [$label, $heading, $intro, $subject] = match ($purpose) {
            self::PASSWORD_RESET => [
                'Password reset',
                'Reset your password',
                'We received a request to reset your Synsyma password. Enter this code in the app to choose a new one.',
                'Reset your Synsyma password',
            ],
            self::PASSWORD_CHANGE => [
                'Password change',
                'Confirm your password change',
                'You asked to change your Synsyma password. Enter this code in the app to continue.',
                'Confirm your Synsyma password change',
            ],
            default => [
                'Email verification',
                'Verify your email',
                'Welcome to Synsyma. Enter this code in the app to confirm your email address and finish setting up your account.',
                'Verify your Synsyma email',
            ],
        };

        $data = [
            'code' => $code,
            'minutes' => $this->ttlMinutes(),
            'name' => $name,
            'label' => $label,
            'heading' => $heading,
            'intro' => $intro,
            'logoUrl' => url('logo.png'),
            'supportEmail' => config('mail.from.address'),
        ];

        try {
            Mail::send(
                ['html' => 'emails.otp', 'text' => 'emails.otp-text'],
                $data,
                fn ($message) => $message->to($email)->subject($subject),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** True for a correct unexpired code. Consumes it unless $consume is false. */
    public function verify(string $email, string $purpose, string $code, bool $consume = true): bool
    {
        $otp = Otp::where('email', $email)->where('purpose', $purpose)->first();

        if (! $otp) {
            return false;
        }

        if ($otp->expires_at->isPast() || $otp->attempts >= (int) config('authentication.otp.max_attempts', 5)) {
            $otp->delete();
            return false;
        }

        $otp->increment('attempts');

        if (! hash_equals($otp->code, $this->hash($email, $purpose, $code))) {
            return false;
        }

        if ($consume) {
            $otp->delete();
        }

        return true;
    }

    public function forget(string $email, string $purpose): void
    {
        Otp::where('email', $email)->where('purpose', $purpose)->delete();
    }

    private function hash(string $email, string $purpose, string $code): string
    {
        return hash_hmac('sha256', "{$email}|{$purpose}|{$code}", (string) config('app.key'));
    }

    public function issueSessionToken(string $email, string $sessionPurpose, int $minutes = 15): string
    {
        $token = \Illuminate\Support\Str::random(64);

        Otp::updateOrCreate(
            ['email' => $email, 'purpose' => $sessionPurpose],
            [
                'code' => $this->hash($email, $sessionPurpose, $token),
                'attempts' => 0,
                'expires_at' => now()->addMinutes($minutes),
            ],
        );

        return $token;
    }

    /**
     * Issue a session token for password reset.
     */
    public function issueResetToken(string $email): string
    {
        return $this->issueSessionToken($email, self::RESET_SESSION);
    }
}