<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\User\Models\Booking;
use Modules\User\Models\Payment;
use Modules\User\Models\UserEmailChange;
use Modules\Chat\Models\DeviceToken;
use Modules\User\Models\Review;

/**
 * Admin: the customers.
 *   GET   users              (can:users.view)    list with search, status filter and a summary
 *   GET   users/{id}         (can:users.view)    one customer with their bookings and reviews
 *   PATCH users/{id}/status  (can:users.manage)  deactivate (reason required) or reactivate
 *
 * Deactivating blocks NEW logins (see STEPS.md for the login check) and ends their current sessions.
 * Their bookings are left as they are. No passwords or tokens are ever returned.
 */
class AdminUserController extends Controller
{
    private const PAID = ['succeeded', 'partially_refunded'];

    private function model(): string
    {
        return config('auth.providers.users.model');
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $U = $this->model();
        $table = (new $U)->getTable();

        $base = $U::query();
        if ($q = trim((string) $request->query('search', ''))) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $base->where(function ($w) use ($like) {
                $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$like]);
            });
        }
        if ($s = $request->query('status')) {
            $base->where('is_active', $s === 'active');
        }

        $page = $base
            ->select("{$table}.*")
            ->addSelect([
                'bookings_count' => Booking::query()->selectRaw('count(*)')->whereColumn('bookings.user_id', "{$table}.id"),
                'paid_total' => Payment::query()->selectRaw('COALESCE(SUM(amount - refunded_total), 0)')
                    ->whereColumn('payments.user_id', "{$table}.id")->whereIn('status', self::PAID),
            ])
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 15));

        $all = $U::query();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($u) => $this->row($u))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'summary' => [
                'total' => (clone $all)->count(),
                'this_month' => (clone $all)->where('created_at', '>=', now()->startOfMonth())->count(),
                'inactive' => (clone $all)->where('is_active', false)->count(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->detail($id)]);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:300', 'required_if:active,false'],
        ]);
        if (! $data['active'] && mb_strlen(trim((string) ($data['reason'] ?? ''))) < 3) {
            return response()->json(['message' => 'Give a short reason.', 'errors' => ['reason' => ['Give a short reason.']]], 422);
        }

        $U = $this->model();
        $user = $U::findOrFail($id);

        if ($data['active']) {
            $user->forceFill(['is_active' => true, 'deactivated_at' => null, 'deactivation_reason' => null, 'deactivated_by' => null])->save();
        } else {
            $user->forceFill([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivation_reason' => trim($data['reason']),
                'deactivated_by' => $request->user()->id,
                'refresh_token' => null,            // they cannot get a new session
                'refresh_token_expires_at' => null,
            ])->save();
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();          // end the sessions they have now
            }
            DeviceToken::where('user_id', $user->id)->delete(); // stop pushes to their phone
        }

        Log::info('admin_user_status', ['admin_id' => $request->user()->id, 'user_id' => $user->id, 'active' => (bool) $data['active'], 'reason' => $data['reason'] ?? null]);

        return response()->json([
            'message' => $data['active'] ? 'Account reactivated.' : 'Account deactivated. They can no longer sign in.',
            'data' => $this->detail($id),
        ]);
    }

    // ------------------------------------------------------------------ shaping

    /** Works whether or not the User model casts the column to a date. */
    private function iso($v): ?string
    {
        return $v ? \Illuminate\Support\Carbon::parse($v)->toIso8601String() : null;
    }

    private function row($u): array
    {
        return [
            'id' => $u->id,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'full_name' => trim($u->first_name . ' ' . $u->last_name),
            'email' => $u->email,
            'email_verified' => $u->email_verified_at !== null,
            'phone' => $u->phone,
            'is_active' => (bool) $u->is_active,
            'last_login_at' => $this->iso($u->last_login_at),
            'created_at' => $this->iso($u->created_at),
            'bookings_count' => (int) ($u->bookings_count ?? 0),
            'paid_total' => round((float) ($u->paid_total ?? 0), 2),
        ];
    }

    private function detail(string $id): array
    {
        $U = $this->model();
        $table = (new $U)->getTable();

        $u = $U::query()->select("{$table}.*")->addSelect([
            'bookings_count' => Booking::query()->selectRaw('count(*)')->whereColumn('bookings.user_id', "{$table}.id"),
            'paid_total' => Payment::query()->selectRaw('COALESCE(SUM(amount - refunded_total), 0)')
                ->whereColumn('payments.user_id', "{$table}.id")->whereIn('status', self::PAID),
        ])->findOrFail($id);

        $bookings = Booking::with('service')->where('user_id', $u->id)->orderByDesc('starts_at')->limit(20)->get();
        $byStatus = Booking::where('user_id', $u->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $reviews = Review::with('booking')->where('user_id', $u->id)->latest()->limit(10)->get();
        $given = Review::where('user_id', $u->id);

        return $this->row($u) + [
            'address' => $u->address,
            'terms_accepted_at' => $this->iso($u->terms_accepted_at),
            'terms_version' => $u->terms_version,
            'deactivated_at' => $this->iso($u->deactivated_at),
            'deactivation_reason' => $u->deactivation_reason,
            'email_changes' => UserEmailChange::historyFor($u->id),
            'stats' => [
                'bookings' => (int) $byStatus->sum(),
                'completed' => (int) ($byStatus['completed'] ?? 0),
                'cancelled' => (int) (($byStatus['cancelled'] ?? 0) + ($byStatus['refunded'] ?? 0)),
                'reviews' => (clone $given)->count(),
                'average_rating' => (clone $given)->count() ? round((float) (clone $given)->avg('rating'), 1) : null,
            ],
            'bookings' => $bookings->map(function (Booking $b) {
                $r = $b->toArrayFlat();

                return [
                    'id' => $r['id'], 'reference' => $r['reference'], 'status' => $r['status'], 'service_name' => $r['service_name'],
                    'total_label' => $r['total_label'], 'starts_at_local' => $r['starts_at_local'], 'timezone' => $r['timezone'],
                ];
            })->values(),
            'reviews' => $reviews->map(fn (Review $r) => [
                'id' => $r->id, 'booking_id' => $r->booking_id, 'rating' => (int) $r->rating, 'comment' => $r->comment,
                'is_hidden' => (bool) $r->is_hidden, 'created_at' => $r->created_at?->toIso8601String(),
                'service_name' => $r->booking?->service_name, 'reference' => $r->booking?->reference,
            ])->values(),
        ];
    }
}