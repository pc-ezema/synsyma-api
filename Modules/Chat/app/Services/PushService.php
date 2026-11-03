<?php

namespace Modules\Push\Services;

use Illuminate\Support\Collection;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\WebPushConfig;
use Modules\Admin\Models\AdminDeviceToken;
use Modules\Chat\Models\DeviceToken;
use Throwable;

class PushService
{
    /** Mobile app users. @param array<string, scalar> $data values are sent as strings */
    public function sendToUser(string $userId, string $title, string $body, array $data = [], ?string $tag = null): void
    {
        $this->deliver(
            DeviceToken::where('user_id', $userId)->pluck('token'),
            DeviceToken::class, $title, $body, $data, $tag,
        );
    }

    /** Admin dashboard browsers. $link opens when the notification is clicked. */
    public function sendToAdmins(array $adminIds, string $title, string $body, array $data = [], ?string $tag = null, ?string $link = null): void
    {
        if ($adminIds === []) {
            return;
        }

        $this->deliver(
            AdminDeviceToken::whereIn('admin_id', $adminIds)->pluck('token'),
            AdminDeviceToken::class, $title, $body, $data, $tag, $link,
        );
    }

    private function deliver(Collection $tokens, string $tokenModel, string $title, string $body, array $data, ?string $tag, ?string $link = null): void
    {
        $tokens = $tokens->unique()->values();

        if ($tokens->isEmpty()) {
            return;
        }

        try {
            $messaging = app(Messaging::class);
        } catch (Throwable $e) {
            report($e); // credentials missing or unreadable
            return;
        }

        $android = ['priority' => 'high', 'ttl' => '86400s', 'notification' => [
            'channel_id' => 'chat_messages',
            'sound' => 'default',
        ]];
        $web = ['headers' => ['Urgency' => 'high']];

        if ($tag) {
            $android['notification']['tag'] = $tag; // a newer message replaces the older one
            $web['notification'] = ['tag' => $tag];
        }
        if ($link && str_starts_with($link, 'https://')) {
            $web['fcm_options'] = ['link' => $link]; // FCM only accepts https links
        }

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData(array_map('strval', $data))
            ->withAndroidConfig(AndroidConfig::fromArray($android))
            ->withApnsConfig(ApnsConfig::fromArray(['payload' => ['aps' => ['sound' => 'default']]]))
            ->withWebPushConfig(WebPushConfig::fromArray($web));

        foreach ($tokens as $token) {
            try {
                $messaging->send($message->withChangedTarget('token', $token));
            } catch (NotFound) {
                $tokenModel::where('token', $token)->delete(); // uninstalled or expired
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}