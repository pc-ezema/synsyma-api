<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\Booking;
use Modules\User\Support\BookingCompletion;

/** The customer finishing a job, or telling us something is wrong with it. */
class BookingCompletionController extends Controller
{
    public function complete(Request $request, string $id): JsonResponse
    {
        $b = Booking::where('user_id', $request->user()->id)->findOrFail($id);

        if (! BookingCompletion::complete($b->id, 'customer', 'Marked completed by the customer.')) {
            throw ValidationException::withMessages(['booking' => ['This job is not in progress, so it cannot be marked completed.']]);
        }

        return response()->json(['message' => 'Thank you. Your job is marked completed.', 'data' => $this->fresh($id)]);
    }

    public function reportProblem(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:1000']]);
        $b = Booking::where('user_id', $request->user()->id)->findOrFail($id);

        BookingCompletion::reportProblem($b, trim($data['note']));

        return response()->json(['message' => 'We have been told. Our team will contact you shortly.', 'data' => $this->fresh($id)]);
    }

    private function fresh(string $id): array
    {
        return Booking::with(['service', 'contractor', 'events', 'payments'])->findOrFail($id)->toArrayFlat(true);
    }
}