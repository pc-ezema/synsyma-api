<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Admin\Models\AppSetting;

/** What the app needs to draw its date picker, plus the business details. */
class BookingRulesController extends Controller
{
    public function show(): JsonResponse
    {
        $s = AppSetting::current();

        return response()->json(['data' => [
            'min_notice_hours' => $s->min_notice_hours,
            'max_days_ahead' => $s->max_days_ahead,
            'business_name' => $s->business_name,
            'support_email' => $s->support_email,
            'support_phone' => $s->support_phone,
        ]]);
    }
}