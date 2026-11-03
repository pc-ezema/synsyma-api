<?php

namespace Modules\Chat\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Admin\Models\Admin;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\User\Models\User;

class ChatService
{
    public const USER = 'user';
    public const ADMIN = 'admin';

    /** Each user has exactly one support thread. */
    public function conversationFor(User $user): Conversation
    {
        return Conversation::firstOrCreate(['user_id' => $user->id], ['status' => 'open']);
    }

    /**
     * One page of messages, oldest first.
     *  - no cursor: the newest messages
     *  - before:    older messages than that message id (scrolling up)
     *  - after:     newer messages than that message id (polling)
     *
     * @return array{0: Collection<int, Message>, 1: bool} messages and whether more exist
     */
    public function page(Conversation $conversation, ?string $before, ?string $after, int $limit = 30): array
    {
        $limit = max(1, min($limit, 50));
        $cursorId = $after ?? $before;
        $ref = $cursorId
            ? $conversation->messages()->withTrashed()->whereKey($cursorId)->first()
            : null;

        if ($ref && $after) {
            $at = $ref->getRawOriginal('created_at');
            $rows = $conversation->messages()
                ->where(fn ($q) => $q->where('created_at', '>', $at)
                    ->orWhere(fn ($q) => $q->where('created_at', $at)->where('id', '>', $ref->id)))
                ->orderBy('created_at')->orderBy('id')
                ->limit($limit + 1)->get();

            return [$rows->take($limit)->values(), $rows->count() > $limit];
        }

        $query = $conversation->messages();

        if ($ref && $before) {
            $at = $ref->getRawOriginal('created_at');
            $query->where(fn ($q) => $q->where('created_at', '<', $at)
                ->orWhere(fn ($q) => $q->where('created_at', $at)->where('id', '<', $ref->id)));
        }

        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit($limit + 1)->get();

        return [$rows->take($limit)->reverse()->values(), $rows->count() > $limit];
    }

    public function send(
        Conversation $conversation,
        string $role,
        string $senderId,
        string $body,
        ?UploadedFile $file,
        ?string $clientId,
    ): Message {
        // The same client_id twice means a retry: return the first message, don't duplicate.
        if ($clientId && $existing = $this->byClientId($conversation, $clientId)) {
            return $existing;
        }

        $attributes = [
            'sender_role' => $role,
            'sender_id' => $senderId,
            'client_id' => $clientId,
            'type' => $file ? 'image' : 'text',
            'body' => $body === '' ? null : $body,
        ];

        if ($file) {
            $attributes += [
                'attachment_path' => $file->store("chat/{$conversation->id}", 'local'), // private disk
                'attachment_name' => Str::limit($file->getClientOriginalName(), 200, ''),
                'attachment_mime' => $file->getMimeType(),
                'attachment_size' => $file->getSize(),
            ];
        }

        try {
            return DB::transaction(function () use ($conversation, $role, $senderId, $attributes) {
                $message = $conversation->messages()->create($attributes);

                $conversation->forceFill([
                    'status' => 'open',
                    'last_message_at' => $message->created_at,
                    'last_message_preview' => $this->preview($message),
                    'last_message_sender' => $role,
                    // The first admin to reply takes the conversation.
                    'assigned_admin_id' => $conversation->assigned_admin_id
                        ?? ($role === self::ADMIN ? $senderId : null),
                ])->save();

                return $message;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (isset($attributes['attachment_path'])) {
                Storage::disk('local')->delete($attributes['attachment_path']);
            }
            if ($clientId && $existing = $this->byClientId($conversation, $clientId)) {
                return $existing;
            }
            throw $e;
        }
    }

    /** Marks the other side's messages as read. Returns how many changed. */
    public function markRead(Conversation $conversation, string $readerRole): int
    {
        return $conversation->messages()
            ->where('sender_role', $readerRole === self::USER ? self::ADMIN : self::USER)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function unreadFor(Conversation $conversation, string $viewerRole): int
    {
        return $conversation->messages()
            ->where('sender_role', $viewerRole === self::USER ? self::ADMIN : self::USER)
            ->whereNull('read_at')
            ->count();
    }

    /** Call after deleting a message, so the inbox preview stays right. */
    public function refreshLastMessage(Conversation $conversation): void
    {
        $last = $conversation->messages()->orderByDesc('created_at')->orderByDesc('id')->first();

        $conversation->forceFill([
            'last_message_at' => $last?->created_at,
            'last_message_preview' => $last ? $this->preview($last) : null,
            'last_message_sender' => $last?->sender_role,
        ])->save();
    }

    // ---- Response shapes ----

    public function conversationArray(Conversation $c, string $viewerRole): array
    {
        $out = [
            'id' => $c->id,
            'status' => $c->status,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_message_preview' => $c->last_message_preview,
            'last_message_sender' => $c->last_message_sender,
            'unread_count' => (int) ($c->getAttribute('unread_count') ?? $this->unreadFor($c, $viewerRole)),
        ];

        if ($viewerRole === self::ADMIN) {
            $c->loadMissing(['user', 'assignedAdmin']);
            $out['user'] = array_intersect_key($c->user->toApiArray(), array_flip([
                'id', 'first_name', 'last_name', 'full_name', 'email', 'phone', 'photo_url',
            ]));
            $out['assigned_admin'] = $c->assignedAdmin
                ? ['id' => $c->assignedAdmin->id, 'name' => $c->assignedAdmin->name]
                : null;
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function messageList(Collection $messages, Conversation $conversation, string $viewerRole, string $viewerId): array
    {
        $adminIds = $messages->where('sender_role', self::ADMIN)->pluck('sender_id')->unique()->all();
        $adminNames = $adminIds ? Admin::whereIn('id', $adminIds)->pluck('name', 'id')->all() : [];
        $owner = $conversation->user;

        return $messages
            ->map(fn (Message $m) => $this->messageArray($m, $viewerRole, $viewerId, $adminNames, $owner))
            ->values()
            ->all();
    }

    public function present(Message $m, Conversation $conversation, string $viewerRole, string $viewerId): array
    {
        return $this->messageList(collect([$m]), $conversation, $viewerRole, $viewerId)[0];
    }

    private function messageArray(Message $m, string $viewerRole, string $viewerId, array $adminNames, ?User $owner): array
    {
        $fromAdmin = $m->sender_role === self::ADMIN;

        return [
            'id' => $m->id,
            'conversation_id' => $m->conversation_id,
            'client_id' => $m->client_id,
            'type' => $m->type,
            'body' => $m->body,
            'attachment' => $m->attachment_path ? [
                'name' => $m->attachment_name,
                'mime' => $m->attachment_mime,
                'size' => $m->attachment_size,
                'url' => route($viewerRole === self::ADMIN ? 'admin.chat.attachment' : 'chat.attachment', ['message' => $m->id]),
            ] : null,
            'sender' => [
                'role' => $m->sender_role,
                'id' => $m->sender_id,
                'name' => $fromAdmin
                    ? ($adminNames[$m->sender_id] ?? 'Synsyma Support')
                    : trim(($owner?->first_name ?? '').' '.($owner?->last_name ?? '')),
            ],
            'is_mine' => $m->sender_role === $viewerRole && $m->sender_id === $viewerId,
            'read_at' => $m->read_at?->toIso8601String(),
            'created_at' => $m->created_at?->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    private function byClientId(Conversation $conversation, string $clientId): ?Message
    {
        return $conversation->messages()->where('client_id', $clientId)->first();
    }

    private function preview(Message $m): string
    {
        $text = trim((string) $m->body);

        if ($m->type === 'image') {
            return $text === '' ? 'Photo' : Str::limit('Photo: '.$text, 120);
        }

        return Str::limit($text, 120);
    }
}