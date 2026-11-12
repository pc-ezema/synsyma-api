<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Admin\Models\AppSetting;

class AdminSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => AppSetting::current()->toArrayFlat()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['nullable', 'string', 'max:120'],
            'support_email' => ['nullable', 'email', 'max:190'],
            'support_phone' => ['nullable', 'string', 'max:40'],
            'business_address' => ['nullable', 'string', 'max:300'],
            'min_notice_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'max_days_ahead' => ['required', 'integer', 'min:1', 'max:365'],
            'quote_valid_days' => ['required', Rule::in([3, 7, 14, 30])],
            'review_edit_hours' => ['required', 'integer', 'min:0', 'max:720'],
        ]);

        foreach (['business_name', 'support_email', 'support_phone', 'business_address'] as $k) {
            $data[$k] = trim((string) ($data[$k] ?? '')) ?: null;
        }

        $s = AppSetting::current();
        $s->update($data);

        return response()->json(['message' => 'Settings saved.', 'data' => $s->fresh()->toArrayFlat()]);
    }
}