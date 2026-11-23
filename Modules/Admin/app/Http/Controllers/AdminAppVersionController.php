<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Models\AppVersion;

class AdminAppVersionController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => collect(AppVersion::PLATFORMS)
            ->mapWithKeys(fn ($p) => [$p => AppVersion::firstOrCreate(['platform' => $p])->toArrayFlat()])]);
    }

    public function update(Request $request, string $platform): JsonResponse
    {
        abort_unless(in_array($platform, AppVersion::PLATFORMS, true), 404);

        $v = '/^\d{1,3}\.\d{1,3}\.\d{1,3}$/';
        $data = $request->validate([
            'min_version' => ['required', 'regex:' . $v],
            'latest_version' => ['required', 'regex:' . $v],
            'store_url' => ['nullable', 'url', 'max:300'],
            'message' => ['nullable', 'string', 'max:300'],
        ], ['regex' => 'Use the form 1.2.3.']);

        if (AppVersion::compare($data['min_version'], $data['latest_version']) > 0) {
            return response()->json([
                'message' => 'The latest version cannot be lower than the minimum.',
                'errors' => ['latest_version' => ['The latest version cannot be lower than the minimum.']],
            ], 422);
        }

        foreach (['store_url', 'message'] as $k) {
            $data[$k] = trim((string) ($data[$k] ?? '')) ?: null;
        }

        $row = AppVersion::firstOrCreate(['platform' => $platform]);
        $row->update($data);
        AppVersion::flush();

        return response()->json(['message' => ucfirst($platform) . ' versions saved.', 'data' => $row->fresh()->toArrayFlat()]);
    }
}