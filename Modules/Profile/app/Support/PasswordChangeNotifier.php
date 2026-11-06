<?php

namespace Modules\Profile\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Modules\Profile\Emails\UserPasswordChangedMail;

/** One place to send the "your password changed" email. A mail failure never blocks the password change. */
class PasswordChangeNotifier
{
    public static function send($user, Request $request, bool $viaReset = false, bool $signedOutOthers = false): void
    {
        if (! filled($user->email)) {
            return;
        }

        try {
            $name = $user->name
                ?? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

            Mail::to($user->email)->send(new UserPasswordChangedMail(
                $name !== '' ? $name : 'there',
                now()->format('D j M Y, g:i A T'),
                self::describeDevice((string) $request->userAgent()),
                $request->ip(),
                $viaReset,
                $signedOutOthers,
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function describeDevice(string $ua): string
    {
        if ($ua === '') {
            return 'Unknown device';
        }
        // The mobile app talks through Dio/Dart.
        if (stripos($ua, 'dart') !== false || stripos($ua, 'dio') !== false) {
            return 'The Synsyma app';
        }
        $os = match (true) {
            stripos($ua, 'iphone') !== false || stripos($ua, 'ipad') !== false => 'iOS',
            stripos($ua, 'android') !== false => 'Android',
            stripos($ua, 'windows') !== false => 'Windows',
            stripos($ua, 'mac os') !== false => 'macOS',
            stripos($ua, 'linux') !== false => 'Linux',
            default => 'Unknown system',
        };
        $browser = match (true) {
            stripos($ua, 'edg') !== false => 'Edge',
            stripos($ua, 'chrome') !== false => 'Chrome',
            stripos($ua, 'firefox') !== false => 'Firefox',
            stripos($ua, 'safari') !== false => 'Safari',
            default => 'a browser',
        };

        return "{$browser} on {$os}";
    }
}