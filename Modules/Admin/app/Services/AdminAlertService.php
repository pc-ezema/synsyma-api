<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Admin\Models\Admin;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Chat\Notifications\NewUserMessageAlert;
use Modules\Chat\Services\ChatService;
use Modules\Push\Services\PushService;
use Throwable;

class AdminAlertService
{
    public function __construct(private PushService $push)
    {
    }

    /** Called after the response is sent, with only the message id. */
    public function newUserMessage(string $messageId): void
    {
        $message = Message::with('conversation.user')->find($messageId);

        if (! $message || $message->sender_role !== ChatService::USER) {
            return;
        }

        $conversation = $message->conversation;
        $user = $conversation->user;
        $admins = $this->recipients($conversation);

        if ($admins->isEmpty()) {
            return;
        }

        $name = trim($user->first_name.' '.$user->last_name) ?: 'A customer';
        $preview = Str::limit(
            $message->type === 'image'
                ? ($message->body ? 'Photo: '.$message->body : 'Sent a photo')
                : (string) $message->body,
            300,
        );
        $link = rtrim((string) config('app.admin_url'), '/').'/chat/'.$conversation->id;

        // The push stays short; the email can show a longer preview.
        $this->push->sendToAdmins(
            $admins->pluck('id')->all(),
            $name,
            Str::limit($preview, 120),
            ['type' => 'chat_user_message', 'conversation_id' => $conversation->id, 'message_id' => $message->id, 'link' => $link],
            'chat-'.$conversation->id,
            $link,
        );

        $unread = $conversation->messages()
            ->where('sender_role', ChatService::USER)
            ->whereNull('read_at')
            ->count();

        $this->email($admins, $conversation, $name, $preview, $link, max(1, $unread));
    }

    /** The assigned admin, or everyone who can reply plus super admins. */
    private function recipients(Conversation $conversation): Collection
    {
        try {
            if ($conversation->assigned_admin_id) {
                $assigned = Admin::where('is_active', true)->find($conversation->assigned_admin_id);

                if ($assigned) {
                    return collect([$assigned]);
                }
            }

            $ids = Admin::permission('chat.reply')->pluck('id')
                ->merge(Admin::role('super_admin')->pluck('id'))
                ->unique();

            return Admin::where('is_active', true)->whereIn('id', $ids)->get();
        } catch (Throwable $e) {
            report($e); // for example the permission has not been seeded yet
            return collect();
        }
    }

    private function email(Collection $admins, Conversation $conversation, string $name, string $preview, string $link, int $unread): void
    {
        if (! config('chat.alert_email', true)) {
            return;
        }

        // At most one email per conversation every 10 minutes.
        if (! Cache::add('chat-alert-mail:'.$conversation->id, 1, now()->addMinutes(10))) {
            return;
        }

        foreach ($admins as $admin) {
            try {
                Notification::route('mail', [$admin->email => $admin->name])
                    ->notify(new NewUserMessageAlert($admin->name, $name, $preview, $link, $unread));
            } catch (Throwable $e) {
                report($e); // one bad address must not stop the others
            }
        }
    }
}