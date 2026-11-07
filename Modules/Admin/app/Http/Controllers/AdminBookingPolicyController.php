<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\BookingPolicy;

/** Admin: the cancellation and change rules. */
class AdminBookingPolicyController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => BookingPolicy::current()->toArrayFlat()]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'free_cancel_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'late_cancel_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'late_cancel_fee_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'free_reschedules' => ['required', 'integer', 'min:0', 'max:10'],
            'max_extra_hours' => ['required', 'integer', 'min:0', 'max:12'],
        ]);

        if ($d['late_cancel_hours'] >= $d['free_cancel_hours']) {
            throw ValidationException::withMessages(['late_cancel_hours' => 'This must be smaller than the free-cancellation window.']);
        }

        $policy = BookingPolicy::current();
        $policy->update($d);

        return response()->json(['message' => 'Policy saved.', 'data' => $policy->toArrayFlat()]);
    }
}