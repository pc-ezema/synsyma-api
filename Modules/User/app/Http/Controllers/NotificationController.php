<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Models\AppNotification;

/** The signed-in customer's own notification list. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $uid = $request->user()->id;
        $page = AppNotification::where('user_id', $uid)->orderByDesc('created_at')->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map->toArrayFlat()->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'unread' => AppNotification::where('user_id', $uid)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function unread(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count()]]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        AppNotification::where('user_id', $request->user()->id)->where('id', $id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'OK', 'data' => null]);
    }

    public function readAll(Request $request): JsonResponse
    {
        AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'All caught up.', 'data' => null]);
    }
}