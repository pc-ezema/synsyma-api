<?php

namespace Modules\User\Support;

use Modules\User\Models\AppNotification;

/** The number shown on the app icon: everything the customer has not read yet. */
class UnreadCount
{
    public static function for(string $userId): int
    {
        return self::notifications($userId) + self::chat($userId);
    }

    public static function notifications(string $userId): int
    {
        return AppNotification::where('user_id', $userId)->whereNull('read_at')->count();
    }

    /**
     * Unread replies from support. Not counted yet: your chat module has its own tables and I have not seen them.
     * To include chat, return the number of unread admin messages in this customer's conversation, for example:
     *   return \Modules\Chat\Models\Message::whereHas('conversation', fn ($q) => $q->where('user_id', $userId))
     *       ->where('sender_role', 'admin')->whereNull('read_at')->count();
     * (adjust the model and column names to yours). The app adds its own chat number too, so it stays right while open.
     */
    public static function chat(string $userId): int
    {
        return 0;
    }
}