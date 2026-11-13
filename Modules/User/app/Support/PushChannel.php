<?php

namespace Modules\User\Support;

use Illuminate\Support\Facades\Log;
use Modules\Push\Services\PushService;

/**
 * Sends one push to every phone a customer is signed in on, through the same PushService (and the same Firebase
 * credentials and device tokens) that chat already uses. It also sets the number on the app icon.
 * Nothing here may ever break the action that caused it.
 */
class PushChannel
{
    /** @param array<string,scalar> $data  sent as strings (FCM rule) */
    public static function send(string $userId, string $title, string $body, array $data = []): void
    {
        try {
            $badge = UnreadCount::for($userId); // counted after the new notification was saved, so it includes it
            app(PushService::class)->sendToUser($userId, $title, $body, array_map('strval', $data + ['badge' => $badge]), null, $badge);
        } catch (\Throwable $e) {
            Log::warning('Push failed', ['user' => $userId, 'error' => $e->getMessage()]);
        }
    }
}