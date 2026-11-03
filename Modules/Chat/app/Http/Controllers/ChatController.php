<?php

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Services\AdminAlertService;
use Modules\Chat\Models\DeviceToken;
use Modules\Chat\Models\Message;
use Modules\Chat\Services\ChatService;

class ChatController extends Controller
{
    public function __construct(private ChatService $chat)
    {
    }

    /** The user's own thread (created on first use) with the unread count for the tab badge. */
    public function conversation(Request $request): JsonResponse
    {
        $conversation = $this->chat->conversationFor($request->user());

        return response()->json(['data' => $this->chat->conversationArray($conversation, ChatService::USER)]);
    }

    public function messages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'before' => ['sometimes', 'uuid'],
            'after' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $user = $request->user();
        $conversation = $this->chat->conversationFor($user);

        [$messages, $more] = $this->chat->page($conversation, $data['before'] ?? null, $data['after'] ?? null, $data['limit'] ?? 30);

        return response()->json([
            'data' => $this->chat->messageList($messages, $conversation, ChatService::USER, $user->id),
            'meta' => ['has_more' => $more],
        ]);
    }

    public function send(Request $request): JsonResponse
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

        $user = $request->user();
        $conversation = $this->chat->conversationFor($user);

        $message = $this->chat->send($conversation, ChatService::USER, $user->id, $body, $file, $data['client_id'] ?? null);

        if ($message->wasRecentlyCreated) {
            $messageId = $message->id;

            // Runs after the response, so the user's send is never slowed down.
            dispatch(fn () => app(AdminAlertService::class)->newUserMessage($messageId))->afterResponse();
        }

        return response()->json([
            'data' => $this->chat->present($message, $conversation, ChatService::USER, $user->id),
        ], 201);
    }

    public function markRead(Request $request): JsonResponse
    {
        $conversation = $this->chat->conversationFor($request->user());

        return response()->json([
            'data' => ['marked' => $this->chat->markRead($conversation, ChatService::USER)],
        ]);
    }

    public function attachment(Request $request, Message $message)
    {
        $conversation = $this->chat->conversationFor($request->user());

        abort_unless($message->conversation_id === $conversation->id && $message->attachment_path, 404);

        return Storage::disk('local')->response($message->attachment_path, $message->attachment_name, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** Called after every login and whenever Firebase rotates the token. */
    public function storeDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:255', 'regex:/^[A-Za-z0-9:_\-]+$/'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
        ]);

        $user = $request->user();

        // If someone else logged in on this phone before, the token moves to this user.
        DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $user->id, 'platform' => $data['platform'], 'last_seen_at' => now()],
        );

        // Keep the 10 most recent phones per user.
        $keep = DeviceToken::where('user_id', $user->id)->orderByDesc('last_seen_at')->limit(10)->pluck('id');
        DeviceToken::where('user_id', $user->id)->whereNotIn('id', $keep)->delete();

        return response()->json(['message' => 'Device registered.']);
    }

    /** Called when the person logs out, so that phone stops getting their messages. */
    public function destroyDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        DeviceToken::where('token', $data['token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['message' => 'Device removed.']);
    }
}
