<?php

namespace Modules\Admin\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Browser push for admins, through Firebase (the same project the dashboard's chat alerts use).
 * It is a normal notification message with a click link. The dashboard's service worker (firebase-messaging-sw.js)
 * only has to start messaging: the browser shows the notification and a click opens the link.
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
            $tokens = DB::table('admin_push_tokens')->whereIn('admin_id', $adminIds)->pluck('token')->filter()->values()->all();
            if (! $tokens) {
                return;
            }
            if (! class_exists(\Kreait\Firebase\Contract\Messaging::class)) {
                Log::info('Admin push not sent: kreait/laravel-firebase is not installed.', ['title' => $title]);

                return;
            }

            $messaging = app(\Kreait\Firebase\Contract\Messaging::class);
            $path = $url ?: '/notifications';
            $base = rtrim((string) (config('app.admin_url') ?: config('app.url')), '/');

            $message = \Kreait\Firebase\Messaging\CloudMessage::new()
                ->withNotification(\Kreait\Firebase\Messaging\Notification::create($title, $body))
                ->withData(['kind' => 'admin_alert', 'title' => $title, 'body' => $body, 'url' => $path, 'tag' => $tag]) // read by the open dashboard tab
                ->withWebPushConfig([
                    'headers' => ['Urgency' => 'high', 'TTL' => '3600'],
                    'notification' => ['tag' => $tag],
                    'fcm_options' => ['link' => $base . $path], // must be a full https address: this is where a click goes
                ]);

            foreach (array_chunk($tokens, 500) as $chunk) {
                $report = $messaging->sendMulticast($message, $chunk);
                $dead = array_merge($report->invalidTokens(), $report->unknownTokens());
                if ($dead) {
                    DB::table('admin_push_tokens')->whereIn('token', $dead)->delete(); // browsers that no longer exist
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Admin push failed', ['error' => $e->getMessage()]);
        }
    }
}