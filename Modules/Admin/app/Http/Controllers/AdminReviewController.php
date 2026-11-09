<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\User\Models\Review;

/**
 * Admin: every review, with a summary, and a way to hide one that breaks the rules.
 * Hidden reviews are kept but do not count towards a contractor's average.
 * Routes: GET reviews (can:reviews.view), PATCH reviews/{id}/visibility (can:reviews.moderate).
 */
class AdminReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'visibility' => ['nullable', 'in:visible,hidden'],
            'contractor_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = Review::query();
        if ($c = $request->query('contractor_id')) {
            $base->where('contractor_id', $c);
        }
        if ($q = trim((string) $request->query('search', ''))) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $base->where(function ($w) use ($like) {
                $w->where('comment', 'like', $like)
                    ->orWhereHas('booking', fn ($b) => $b->where('reference', 'like', $like)->orWhere('service_name', 'like', $like)
                        ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like)))
                    ->orWhereHas('contractor', fn ($k) => $k->where('name', 'like', $like));
            });
        }

        // Summary over the same search and contractor, counting only visible reviews.
        $visible = (clone $base)->where('is_hidden', false);
        $count = (clone $visible)->count();
        $dist = (clone $visible)->selectRaw('rating, count(*) as n')->groupBy('rating')->pluck('n', 'rating');
        $summary = [
            'average' => $count ? round((float) (clone $visible)->avg('rating'), 2) : null,
            'count' => $count,
            'hidden' => (clone $base)->where('is_hidden', true)->count(),
            'distribution' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($r) => [(string) $r => (int) ($dist[$r] ?? 0)]),
        ];

        $list = clone $base;
        if ($r = $request->query('rating')) {
            $list->where('rating', (int) $r);
        }
        if ($v = $request->query('visibility')) {
            $list->where('is_hidden', $v === 'hidden');
        }
        $page = $list->with(['booking.user', 'contractor'])->orderByDesc('created_at')->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Review $r) => $this->row($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'summary' => $summary,
        ]);
    }

    public function visibility(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'hidden' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);
        $review = Review::with(['booking.user', 'contractor'])->findOrFail($id);

        if ($data['hidden']) {
            $reason = trim((string) ($data['reason'] ?? ''));
            if (mb_strlen($reason) < 3) {
                return response()->json(['message' => 'Give a short reason for hiding this review.', 'errors' => ['reason' => ['Give a short reason for hiding this review.']]], 422);
            }
            $review->forceFill(['is_hidden' => true, 'hidden_reason' => $reason, 'hidden_by' => $request->user()?->id])->save();
        } else {
            $review->forceFill(['is_hidden' => false, 'hidden_reason' => null, 'hidden_by' => null])->save();
        }

        Log::info($data['hidden'] ? 'review.hidden' : 'review.shown', ['review' => $review->id, 'by' => $request->user()?->id]);

        return response()->json(['message' => $data['hidden'] ? 'Review hidden.' : 'Review is visible again.', 'data' => $this->row($review)]);
    }

    private function row(Review $r): array
    {
        $b = $r->booking;

        return $r->toArrayFlat(true) + [
            'booking_id' => $r->booking_id,
            'reference' => $b?->reference,
            'service_name' => $b?->service_name,
            'customer_name' => $b?->customerName(),
            'customer_email' => $b?->user?->email,
            'contractor_id' => $r->contractor_id,
            'contractor_name' => $r->contractor?->name,
        ];
    }
}