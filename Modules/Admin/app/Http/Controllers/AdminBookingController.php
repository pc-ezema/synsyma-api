<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\Booking;
use Modules\Admin\Models\Contractor;

/** Admin: see every booking, assign a contractor, move it along. */
class AdminBookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Booking::with(['user', 'contractor', 'service'])->orderByDesc('starts_at');

        if (in_array($request->query('status'), Booking::STATUSES, true)) {
            $q->where('status', $request->query('status'));
        }
        if ($s = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('reference', 'like', "%{$s}%")
                ->orWhere('service_name', 'like', "%{$s}%")
                ->orWhere('city', 'like', "%{$s}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$s}%")));
        }

        $page = $q->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Booking $b) => $b->toArrayFlat(false, true))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function summary(): JsonResponse
    {
        $counts = Booking::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $out = ['all' => (int) $counts->sum()];
        foreach (Booking::STATUSES as $s) {
            $out[$s] = (int) ($counts[$s] ?? 0);
        }

        return response()->json(['data' => $out]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->load($id)->toArrayFlat(true, true)]);
    }

    /** Approved contractors who offer this booking's category, those covering the address first. */
    public function contractorOptions(string $id): JsonResponse
    {
        $booking = Booking::with('service')->findOrFail($id);
        $categoryId = $booking->service?->category_id;

        $rows = Contractor::with('areas')->where('status', 'approved')
            ->whereHas('categories', fn ($c) => $c->where('service_categories.id', $categoryId))
            ->orderBy('name')->get()
            ->map(function (Contractor $c) use ($booking) {
                $covers = $c->areas->contains(fn ($a) => $a->country === $booking->country
                    && strcasecmp($a->region, $booking->region) === 0
                    && ($a->city === null || strcasecmp($a->city, $booking->city) === 0));

                return [
                    'id' => $c->id, 'name' => $c->name, 'type' => $c->type,
                    'phone' => $c->phone, 'email' => $c->email, 'covers_area' => $covers,
                ];
            })
            ->sortByDesc('covers_area')->values();

        return response()->json(['data' => $rows]);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);
        $data = $request->validate(['contractor_id' => ['required', 'uuid']]);

        if (! in_array($booking->status, ['confirmed', 'assigned'], true)) {
            throw ValidationException::withMessages(['contractor_id' => 'Only a confirmed (paid) booking can be assigned.']);
        }
        $contractor = Contractor::where('status', 'approved')->find($data['contractor_id']);
        if (! $contractor) {
            throw ValidationException::withMessages(['contractor_id' => 'Choose an approved contractor.']);
        }

        $from = $booking->status;
        $booking->fill(['contractor_id' => $contractor->id, 'status' => 'assigned', 'assigned_at' => now()])->save();
        $booking->log('assigned', 'admin', $from, 'assigned', $contractor->name);

        return response()->json(['message' => "Assigned to {$contractor->name}.", 'data' => $this->load($id)->toArrayFlat(true, true)]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);
        $data = $request->validate([
            'status' => ['required', Rule::in(Booking::STATUSES)],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $to = $data['status'];
        $note = trim((string) ($data['note'] ?? '')) ?: null;

        if ($to === 'assigned') {
            throw ValidationException::withMessages(['status' => 'Use Assign contractor to assign a booking.']);
        }
        if (! in_array($to, Booking::TRANSITIONS[$booking->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "A {$booking->status} booking cannot be set to {$to}."]);
        }
        if ($to === 'cancelled' && ! $note) {
            throw ValidationException::withMessages(['note' => 'Give a short reason for cancelling.']);
        }

        $from = $booking->status;
        $booking->status = $to;
        if ($to === 'confirmed') {
            $booking->contractor_id = null;   // back from "assigned": the contractor is released
            $booking->assigned_at = null;
        }
        if ($to === 'completed') {
            $booking->completed_at = now();
        }
        if ($to === 'cancelled') {
            $booking->cancelled_at = now();
        }
        $booking->save();
        $booking->log('status', 'admin', $from, $to, $note);

        return response()->json(['message' => 'Booking updated.', 'data' => $this->load($id)->toArrayFlat(true, true)]);
    }

    public function updateNotes(Request $request, string $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);
        $data = $request->validate(['admin_notes' => ['nullable', 'string', 'max:2000']]);
        $booking->update(['admin_notes' => trim((string) ($data['admin_notes'] ?? '')) ?: null]);

        return response()->json(['message' => 'Notes saved.', 'data' => $this->load($id)->toArrayFlat(true, true)]);
    }

    private function load(string $id): Booking
    {
        return Booking::with(['user', 'contractor', 'service', 'events', 'payments'])->findOrFail($id);
    }
}