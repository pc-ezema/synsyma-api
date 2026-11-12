<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Models\Booking;
use Modules\User\Models\Review;
use Modules\Admin\Support\Quotes;

/**
 * GET /users/home: everything the Home tab needs about this customer in one call.
 *
 *  - actions:      things waiting on them (pay, accept a quote, rate a job), at most 3
 *  - next_job:     their next confirmed / assigned / in-progress booking, or null
 *  - book_again:   services they have had done before, newest first, at most 6
 *  - has_bookings: false for a brand-new customer (the app then shows "How it works")
 */
class HomeController extends Controller
{
    private const DONE = ['completed', 'cancelled', 'refunded'];
 
    public function summary(Request $request): JsonResponse
    {
        $uid = $request->user()->id;
 
        $open = Booking::with(['service', 'contractor'])
            ->where('user_id', $uid)
            ->whereNotIn('status', self::DONE)
            ->orderBy('starts_at')
            ->get();
 
        $actions = [];
 
        foreach ($open as $b) {
            if ($b->status === 'pending_payment' && (float) $b->total > 0) {
                $actions[] = $this->action('pay', $b, 'Pay to confirm your booking', $b->service_name . ' · ' . $b->money($b->total));
            }
        }
        foreach ($open as $b) {
            if ($b->status === 'quote_sent') {
                $q = Quotes::info($b);
                if ($q['can_accept'] ?? false) {
                    $actions[] = $this->action('quote', $b, 'Your quote is ready', $b->service_name . (($q['price_label'] ?? null) ? ' · ' . $q['price_label'] : ''));
                }
            }
        }
 
        $rate = Booking::where('user_id', $uid)
            ->where('status', 'completed')
            ->whereNotNull('contractor_id')
            ->whereDoesntHave('review')
            ->where('completed_at', '>=', now()->subDays(Review::EDIT_DAYS))
            ->latest('completed_at')
            ->first();
        if ($rate) {
            $actions[] = $this->action('rate', $rate, 'How was your ' . $rate->service_name . '?', 'Tap to rate it. It takes a few seconds.');
        }
 
        $next = $open->first(fn (Booking $b) => in_array($b->status, ['confirmed', 'assigned', 'en_route', 'in_progress'], true));
 
        $again = Booking::with('service')
            ->where('user_id', $uid)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->limit(30)
            ->get()
            ->filter(fn (Booking $b) => $b->service && ! $b->service->trashed() && $b->service->is_active)
            ->unique(fn (Booking $b) => mb_strtolower($b->service_name))
            ->take(6)
            ->map(fn (Booking $b) => [
                'service_id' => $b->service_id,
                'name' => $b->service_name,
                'category_name' => $b->category_name,
                'image_url' => $b->service->imageUrl(),
            ])
            ->values();
 
        return response()->json(['data' => [
            'actions' => array_slice($actions, 0, 3),
            'next_job' => $next?->toArrayFlat(),
            'book_again' => $again,
            'has_bookings' => Booking::where('user_id', $uid)->exists(),
        ]]);
    }
 
    private function action(string $type, Booking $b, string $title, string $subtitle): array
    {
        return ['type' => $type, 'booking_id' => $b->id, 'title' => $title, 'subtitle' => $subtitle];
    }
}