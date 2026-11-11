<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AdminNotification;
use Modules\Admin\Support\AdminNotifier;

/**
 * The signed-in admin's alerts. No permission middleware: every admin has a bell, and each one only gets
 * the kinds of alert their role can act on. Read state, push choices and browsers are all per admin.
 *
 *   GET    notifications               list (?filter=unread, ?per_page=)  + unread_count
 *   GET    notifications/unread-count  { unread }
 *   POST   notifications/read-all
 *   POST   notifications/{id}/read
 *   GET    notifications/preferences   what this admin gets, push and email per kind
 *   PUT    notifications/preferences   { prefs: { quote_requested: { push, email }, ... } }
 *   POST   notifications/devices       { token }   this browser turned alerts on
 *   DELETE notifications/devices       { token }   this browser turned them off (or signed out)
 */
class AdminNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['filter' => ['nullable', 'in:all,unread'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $admin = $request->user();
        $baseline = $this->baseline($admin);

        $q = $this->visible($admin)->orderByDesc('created_at');
        if ($request->query('filter') === 'unread') {
            $this->unreadOnly($q, $admin, $baseline);
        }
        $page = $q->paginate((int) $request->query('per_page', 15));

        $readIds = DB::table('admin_notification_reads')->where('admin_id', $admin->id)
            ->whereIn('notification_id', $page->getCollection()->pluck('id'))->pluck('notification_id')->flip();

        return response()->json([
            'data' => $page->getCollection()->map(fn (AdminNotification $n) => [
                'id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body, 'link' => $n->link,
                'read' => $n->created_at->lte($baseline) || $readIds->has($n->id),
                'created_at' => $n->created_at->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'unread_count' => $this->unread($admin, $baseline),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $admin = $request->user();

        return response()->json(['data' => ['unread' => $this->unread($admin, $this->baseline($admin))]]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $n = $this->visible($admin)->findOrFail($id);
        DB::table('admin_notification_reads')->insertOrIgnore(['admin_id' => $admin->id, 'notification_id' => $n->id, 'read_at' => now()]);

        return response()->json(['message' => 'Marked as read.', 'data' => ['unread' => $this->unread($admin, $this->baseline($admin))]]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $admin = $request->user();
        DB::table('admin_notification_cursors')->updateOrInsert(['admin_id' => $admin->id], ['read_all_at' => now()]);

        return response()->json(['message' => 'All caught up.', 'data' => ['unread' => 0]]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'types' => AdminNotifier::preferencesFor($request->user()),
            'browsers' => DB::table('admin_push_tokens')->where('admin_id', $request->user()->id)->count(),
        ]]);
    }

    public function savePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prefs' => ['required', 'array'],
            'prefs.*.push' => ['required', 'boolean'],
            'prefs.*.email' => ['required', 'boolean'],
        ]);
        $admin = $request->user();
        $allowed = collect(AdminNotifier::preferencesFor($admin))->pluck('type')->all();

        foreach ($data['prefs'] as $type => $p) {
            if (! in_array($type, $allowed, true)) {
                continue; // not a kind this admin can receive
            }
            $exists = DB::table('admin_notification_prefs')->where(['admin_id' => $admin->id, 'type' => $type])->exists();
            $row = ['push' => (bool) $p['push'], 'email' => (bool) $p['email'], 'updated_at' => now()];
            $exists
                ? DB::table('admin_notification_prefs')->where(['admin_id' => $admin->id, 'type' => $type])->update($row)
                : DB::table('admin_notification_prefs')->insert($row + ['admin_id' => $admin->id, 'type' => $type, 'created_at' => now()]);
        }

        return response()->json(['message' => 'Alert settings saved.', 'data' => ['types' => AdminNotifier::preferencesFor($admin)]]);
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:400']]);
        $admin = $request->user();
        $row = ['admin_id' => $admin->id, 'user_agent' => Str::limit((string) $request->userAgent(), 250, ''), 'last_seen_at' => now(), 'updated_at' => now()];

        // A browser that was used by another admin before now belongs to this one.
        DB::table('admin_push_tokens')->where('token', $data['token'])->exists()
            ? DB::table('admin_push_tokens')->where('token', $data['token'])->update($row)
            : DB::table('admin_push_tokens')->insert($row + ['id' => (string) Str::uuid(), 'token' => $data['token'], 'created_at' => now()]);

        return response()->json(['message' => 'Browser alerts are on.', 'data' => []]);
    }

    public function forgetDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:400']]);
        DB::table('admin_push_tokens')->where('token', $data['token'])->where('admin_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Browser alerts are off.', 'data' => []]);
    }

    // ------------------------------------------------------------------ helpers

    /** Alerts for kinds this admin's role can open. */
    private function visible($admin)
    {
        return AdminNotification::query()->whereIn('permission', AdminNotifier::allowedPermissions($admin) ?: ['__none__']);
    }

    /** Anything before this counts as read: the latest "mark all as read", or when the admin account was made. */
    private function baseline($admin)
    {
        $cursor = DB::table('admin_notification_cursors')->where('admin_id', $admin->id)->value('read_all_at');
        $made = $admin->created_at ? \Illuminate\Support\Carbon::parse($admin->created_at) : now()->subYears(5);

        return $cursor ? \Illuminate\Support\Carbon::parse($cursor)->max($made) : $made;
    }

    private function unreadOnly($q, $admin, $baseline): void
    {
        $q->where('created_at', '>', $baseline)->whereNotExists(function ($s) use ($admin) {
            $s->select(DB::raw(1))->from('admin_notification_reads')
                ->whereColumn('admin_notification_reads.notification_id', 'admin_notifications.id')
                ->where('admin_notification_reads.admin_id', $admin->id);
        });
    }

    private function unread($admin, $baseline): int
    {
        $q = $this->visible($admin);
        $this->unreadOnly($q, $admin, $baseline);

        return (int) $q->count();
    }
}