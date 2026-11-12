<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Models\Booking;
use Modules\User\Support\BookingCompletion;

class AdminBookingProblemController extends Controller
{
    public function resolve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $b = Booking::findOrFail($id);

        BookingCompletion::resolveProblem($b, trim((string) ($data['note'] ?? '')) ?: null);

        return response()->json(['message' => 'Problem marked as resolved.', 'data' => Booking::with(['service', 'contractor', 'events', 'payments'])->findOrFail($id)->toArrayFlat(true, true)]);
    }
}