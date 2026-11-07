<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\Address;
use Modules\User\Models\Booking;
use Modules\Admin\Models\PromoCode;
use Modules\Admin\Models\Service;
use Modules\User\Support\PriceCalculator;
use Modules\Admin\Support\Promos;

/** The signed-in customer's own bookings. */
class BookingController extends Controller
{
    private const DONE = ['completed', 'cancelled', 'refunded'];

    public function index(Request $request): JsonResponse
    {
        $uid = $request->user()->id;
        $q = Booking::where('user_id', $uid)->with(['service', 'contractor']);

        match ($request->query('scope')) {
            'upcoming', 'active' => $q->whereNotIn('status', self::DONE)->orderBy('starts_at'),
            'cancelled' => $q->whereIn('status', ['cancelled', 'refunded'])->orderByDesc('starts_at'),
            'completed' => $q->where('status', 'completed')->orderByDesc('starts_at'),
            'past' => $q->whereIn('status', self::DONE)->orderByDesc('starts_at'),
            default => $q->orderByDesc('starts_at'),
        };

        $page = $q->paginate(20);

        $by = Booking::where('user_id', $uid)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $counts = [
            'upcoming' => (int) $by->except(self::DONE)->sum(),
            'cancelled' => (int) (($by['cancelled'] ?? 0) + ($by['refunded'] ?? 0)),
            'completed' => (int) ($by['completed'] ?? 0),
        ];

        return response()->json([
            'data' => $page->getCollection()->map->toArrayFlat()->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'counts' => $counts],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $booking = Booking::where('user_id', $request->user()->id)->with(['service', 'contractor', 'events', 'payments'])->findOrFail($id);

        return response()->json(['data' => $booking->toArrayFlat(true)]);
    }

    /** The price breakdown shown on the review screen. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'uuid'],
            'address_id' => ['sometimes', 'nullable', 'uuid'],
            'promo_code' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $service = $this->bookableService($data['service_id']);
        $address = ! empty($data['address_id'])
            ? Address::where('user_id', $request->user()->id)->findOrFail($data['address_id'])
            : null;

        // A bad code answers 422 on "promo_code" with a message the app shows under the code field.
        $price = PriceCalculator::quote($service, $address?->country, $address?->region, $data['promo_code'] ?? null, $request->user()->id);

        return response()->json(['data' => $price + [
            'service_name' => $service->name,
            'price_type' => $service->price_type,
            'included_hours' => $service->included_hours,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'uuid'],
            'address_id' => ['required', 'uuid'],
            'starts_at' => ['required', 'date'],       // ISO 8601 with offset, e.g. 2026-11-10T09:00:00-05:00
            'timezone' => ['sometimes', 'nullable', 'timezone:all'], // e.g. America/Toronto. If missing, the offset in starts_at is used.
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'promo_code' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $user = $request->user();
        $service = $this->bookableService($data['service_id']);
        $address = Address::where('user_id', $user->id)->find($data['address_id']);
        if (! $address) {
            throw ValidationException::withMessages(['address_id' => 'Choose one of your saved addresses.']);
        }

        $tz = $data['timezone'] ?? Carbon::parse($data['starts_at'])->getTimezone()->getName(); // e.g. "-05:00" when only an offset is sent
        $start = $this->checkStart($data['starts_at'], $tz);
        $booking = DB::transaction(function () use ($user, $service, $address, $data, $start, $tz) {
            // Lock the code while we count its uses, so two people cannot take the last one together.
            if ($code = Promos::normalize($data['promo_code'] ?? null)) {
                PromoCode::where('code', $code)->lockForUpdate()->first();
            }
            $price = PriceCalculator::quote($service, $address->country, $address->region, $data['promo_code'] ?? null, $user->id);

            $booking = Booking::create([
                'reference' => Booking::newReference(),
                'user_id' => $user->id,
                'service_id' => $service->id,
                // A code can make a booking free: nothing to pay, so it is confirmed straight away.
                'status' => $price['is_quote'] ? 'quote_requested' : ($price['total'] <= 0 ? 'confirmed' : 'pending_payment'),
                'starts_at' => $start->copy()->utc(),
                'timezone' => $tz,
                'country' => $address->country,
                'address_line1' => $address->address_line1,
                'address_line2' => $address->address_line2,
                'city' => $address->city,
                'region' => $address->region,
                'postal_code' => $address->postal_code,
                'instructions' => $address->instructions,
                'service_name' => $service->name,
                'category_name' => $service->category?->name,
                'tier' => $service->tier,
                'price_type' => $service->price_type,
                'currency' => $service->currency,
                'base_price' => $service->base_price,
                'included_hours' => $service->included_hours,
                'additional_hour_price' => $service->additional_hour_price,
                'subtotal' => $price['subtotal'],
                'discount_total' => $price['discount_total'],
                'tax_total' => $price['tax_total'],
                'total' => $price['total'],
                'promo_code' => $price['promo_code'],
                'notes' => isset($data['notes']) ? trim($data['notes']) ?: null : null,
            ]);
            $booking->log('created', 'customer', null, $booking->status, $price['promo_code'] ? 'Promo code ' . $price['promo_code'] . ' applied.' : null);

            return $booking;
        });

        return response()->json(['message' => 'Booking created.', 'data' => $booking->load('service', 'contractor', 'events', 'payments')->toArrayFlat(true)], 201);
    }

    private function bookableService(string $id): Service
    {
        $service = Service::with('category')->where('is_active', true)->find($id);
        if (! $service || ! $service->category || ! $service->category->is_active) {
            throw ValidationException::withMessages(['service_id' => 'This service is not available right now.']);
        }

        return $service;
    }

    /** Local to the customer: a start time on the step, inside opening hours, with enough notice. */
    private function checkStart(string $iso, string $tz): Carbon
    {
        $cfg = config('booking');
        $start = Carbon::parse($iso)->setTimezone($tz);

        if ($start->second !== 0 || $start->minute % $cfg['step_minutes'] !== 0) {
            throw ValidationException::withMessages(['starts_at' => 'Choose a start time on the hour or half hour.']);
        }
        $minutes = $start->hour * 60 + $start->minute;
        if ($minutes < $cfg['day_start_hour'] * 60 || $minutes > $cfg['day_end_hour'] * 60) {
            throw ValidationException::withMessages(['starts_at' => sprintf('Start times run from %d:00 to %d:00.', $cfg['day_start_hour'], $cfg['day_end_hour'])]);
        }
        if ($start->lt(now()->addHours($cfg['min_notice_hours']))) {
            throw ValidationException::withMessages(['starts_at' => "Please book at least {$cfg['min_notice_hours']} hours ahead."]);
        }
        if ($start->gt(now()->addDays($cfg['max_days_ahead']))) {
            throw ValidationException::withMessages(['starts_at' => "You can book up to {$cfg['max_days_ahead']} days ahead."]);
        }

        return $start;
    }
}