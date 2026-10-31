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
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Otp::updateOrCreate(
            ['email' => $email, 'purpose' => $purpose],
            [
                'code' => $this->hash($email, $purpose, $code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
            ],
        );

        $reset = $purpose === self::PASSWORD_RESET;

        $data = [
            'code' => $code,
            'minutes' => $this->ttlMinutes(),
            'name' => $name,
            'label' => $reset ? 'Password reset' : 'Email verification',
            'heading' => $reset ? 'Reset your password' : 'Verify your email',
            'intro' => $reset
                ? 'We received a request to reset your '.config('app.name').' password. Enter this code in the app to choose a new one.'
                : 'Welcome to '.config('app.name').'. Enter this code in the app to confirm your email address and finish setting up your account.',
            'logoUrl' => url('logo.png'),
            'supportEmail' => config('mail.from.address'),
        ];

        $subject = $reset ? 'Reset your '.config('app.name').' password' : 'Verify your '.config('app.name').' email';

        try {
            Mail::send(
                ['html' => 'emails.otp', 'text' => 'emails.otp-text'],
                $data,
                fn ($message) => $message->to($email)->subject($subject),
            );
        } catch (Throwable $e) {
            report($e); // the person can tap "resend" if the email never arrives
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

    /** After a correct reset code: a one-time token that allows the password change. */
    public function issueResetToken(string $email): string
    {
        $token = \Illuminate\Support\Str::random(64);

        Otp::updateOrCreate(
            ['email' => $email, 'purpose' => self::RESET_SESSION],
            [
                'code' => $this->hash($email, self::RESET_SESSION, $token),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(15),
            ],
        );

        return $token;
    }

    private function hash(string $email, string $purpose, string $code): string
    {
        return hash_hmac('sha256', "{$email}|{$purpose}|{$code}", (string) config('app.key'));
    }
}