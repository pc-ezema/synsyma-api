<?php

namespace Modules\Admin\Support;

use Illuminate\Support\Facades\Log;
use Modules\Push\Services\PushService;

/**
 * Browser push for admins. It goes through the same PushService (and the same Firebase credentials and
 * admin device tokens) that the chat alerts already use, so nothing extra has to be installed.
 * A failure here is only logged: it must never break the booking, review or payment that caused it.
 */
class AdminPush
{
    /** @param array<int,string> $adminIds */
    public static function send(array $adminIds, string $title, string $body, ?string $url, string $tag): void
    {
        try {
            if (! $adminIds) {
                return;
            }
            $path = $url ?: '/notifications';
            $base = rtrim((string) (config('app.admin_url') ?: config('app.url')), '/');

            app(PushService::class)->sendToAdmins(
                $adminIds, $title, $body,
                ['kind' => 'admin_alert', 'title' => $title, 'body' => $body, 'url' => $path, 'tag' => $tag],
                $tag, $base . $path, // the link must be a full https address
            );
        } catch (\Throwable $e) {
            Log::warning('Admin push failed', ['error' => $e->getMessage()]);
        }
    }
}