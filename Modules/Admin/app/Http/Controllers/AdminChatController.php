<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\AdminDeviceToken;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Chat\Services\ChatService;
use Modules\Push\Services\PushService;
use Illuminate\Support\Str;

class AdminChatController extends Controller
{
    public function __construct(private ChatService $chat)
    {
    }

    /** The inbox: conversations with at least one message, newest first. */
    public function conversations(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'closed'])],
            'unread' => ['sometimes', 'boolean'],
            'mine' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Conversation::query()
            ->whereNotNull('last_message_at')
            ->with(['user', 'assignedAdmin'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('sender_role', ChatService::USER)->whereNull('read_at')]);

        if (isset($f['status'])) {
            $query->where('status', $f['status']);
        }
        if ($request->boolean('unread')) {
            $query->whereHas('messages', fn ($q) => $q->where('sender_role', ChatService::USER)->whereNull('read_at'));
        }
        if ($request->boolean('mine')) {
            $query->where('assigned_admin_id', $request->user()->id);
        }
        if (! empty($f['search'])) {
            $term = '%'.addcslashes(trim($f['search']), '%_\\').'%';
            $query->whereHas('user', fn ($q) => $q->where(fn ($q) => $q
                ->where('first_name', 'like', $term)
                ->orWhere('last_name', 'like', $term)
                ->orWhere('email', 'like', $term)));
        }

        $page = $query->orderByDesc('last_message_at')->paginate($f['per_page'] ?? 20);

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn ($c) => $this->chat->conversationArray($c, ChatService::ADMIN))
                ->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Conversation $conversation): JsonResponse
    {
        return response()->json(['data' => $this->chat->conversationArray($conversation, ChatService::ADMIN)]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'before' => ['sometimes', 'uuid'],
            'after' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        [$messages, $more] = $this->chat->page($conversation, $data['before'] ?? null, $data['after'] ?? null, $data['limit'] ?? 30);

        return response()->json([
            'data' => $this->chat->messageList($messages, $conversation, ChatService::ADMIN, $request->user()->id),
            'meta' => ['has_more' => $more],
        ]);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000', 'required_without:attachment'],
            'attachment' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'required_without:body'],
            'client_id' => ['nullable', 'uuid'],
        ], [
            'attachment.max' => 'The photo must be 5MB or smaller.',
        ]);

        $body = trim((string) ($data['body'] ?? ''));
        $file = $request->file('attachment');

        if ($body === '' && ! $file) {
            throw ValidationException::withMessages(['body' => 'Write a message or attach a photo.']);
        }

        $admin = $request->user();
        $message = $this->chat->send($conversation, ChatService::ADMIN, $admin->id, $body, $file, $data['client_id'] ?? null);

        // A retry returns the existing message, and must not notify twice.
        if ($message->wasRecentlyCreated) {
            $userId = $conversation->user_id;
            $tag = 'chat-'.$conversation->id;
            $preview = $message->type === 'image'
                ? ($message->body ? 'Photo: '.$message->body : 'Sent you a photo')
                : (string) $message->body;
            $payload = [
                'type' => 'chat',
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ];

            // Runs after the response is sent, so the admin never waits for Firebase.
            dispatch(fn () => app(PushService::class)->sendToUser($userId, 'Synsyma Support', Str::limit($preview, 120), $payload, $tag))
                ->afterResponse();
        }

        return response()->json([
            'data' => $this->chat->present($message, $conversation, ChatService::ADMIN, $admin->id),
        ], 201);
    }

    public function markRead(Conversation $conversation): JsonResponse
    {
        return response()->json([
            'data' => ['marked' => $this->chat->markRead($conversation, ChatService::ADMIN)],
        ]);
    }

    /** Close or reopen a conversation, or hand it to another admin (null to unassign). */
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'closed'])],
            'assigned_admin_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists(Admin::class, 'id')->where('is_active', true),
            ],
        ]);

        $conversation->fill($data)->save();

        return response()->json([
            'message' => 'Conversation updated.',
            'data' => $this->chat->conversationArray($conversation->refresh(), ChatService::ADMIN),
        ]);
    }

    public function destroyMessage(Message $message): JsonResponse
    {
        $conversation = $message->conversation;

        $message->delete(); // soft delete, hidden from both sides
        $this->chat->refreshLastMessage($conversation);

        return response()->json(['message' => 'Message deleted.']);
    }

    /** For the sidebar badge. */
    public function unreadCount(): JsonResponse
    {
        $unread = fn ($q) => $q->where('sender_role', ChatService::USER)->whereNull('read_at');

        return response()->json([
            'data' => [
                'messages' => Message::where('sender_role', ChatService::USER)->whereNull('read_at')->count(),
                'conversations' => Conversation::whereHas('messages', $unread)->count(),
            ],
        ]);
    }

    public function attachment(Message $message)
    {
        abort_unless($message->attachment_path, 404);

        return Storage::disk('local')->response($message->attachment_path, $message->attachment_name, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function storeDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:255', 'regex:/^[A-Za-z0-9:_\-]+$/'],
        ]);

        $admin = $request->user();

        AdminDeviceToken::updateOrCreate(
            ['token' => $data['token']],
            ['admin_id' => $admin->id, 'platform' => 'web', 'last_seen_at' => now()],
        );

        // Keep the 10 most recent browsers per admin.
        $keep = AdminDeviceToken::where('admin_id', $admin->id)->orderByDesc('last_seen_at')->limit(10)->pluck('id');
        AdminDeviceToken::where('admin_id', $admin->id)->whereNotIn('id', $keep)->delete();

        return response()->json(['message' => 'Browser registered.']);
    }

    public function destroyDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        AdminDeviceToken::where('token', $data['token'])->where('admin_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Browser removed.']);
    }
}