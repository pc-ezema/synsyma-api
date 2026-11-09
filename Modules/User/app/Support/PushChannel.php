<?php

namespace Modules\User\Support;

use Illuminate\Support\Facades\Log;
use Modules\Chat\Models\DeviceToken;

/**
 * Sends one push to every phone a customer is signed in on.
 * Uses kreait/laravel-firebase when it is installed. If your chat pushes already go through another class,
 * swap the body of deliver() for a call to it. Nothing here may ever break the action that caused it.
 */
class PushChannel
{
    /** @param array<string,string> $data  must be strings (FCM rule) */
    public static function send(string $userId, string $title, string $body, array $data = []): void
    {
        try {
            $tokens = DeviceToken::where('user_id', $userId)->pluck('token')->filter()->values()->all();
            if (! $tokens) {
                return;
            }
            self::deliver($tokens, $title, $body, array_map('strval', $data));
        } catch (\Throwable $e) {
            Log::warning('Push failed', ['user' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private static function deliver(array $tokens, string $title, string $body, array $data): void
    {
        if (! class_exists(\Kreait\Firebase\Contract\Messaging::class)) {
            Log::info('Push not sent: kreait/laravel-firebase is not installed. Wire PushChannel::deliver to your chat push code.', ['title' => $title]);

            return;
        }

        $messaging = app(\Kreait\Firebase\Contract\Messaging::class);
        $message = \Kreait\Firebase\Messaging\CloudMessage::new()
            ->withNotification(\Kreait\Firebase\Messaging\Notification::create($title, $body))
            ->withData($data);

        $report = $messaging->sendMulticast($message, $tokens);

        // Forget phones that no longer exist, so we stop sending to them.
        $dead = array_merge($report->invalidTokens(), $report->unknownTokens());
        if ($dead) {
            DeviceToken::whereIn('token', $dead)->delete();
        }
    }
}