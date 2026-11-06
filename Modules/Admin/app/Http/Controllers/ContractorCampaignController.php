<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\ContractorCampaign;

/**
 * Admin: one row per advert / QR code. The QR image itself is drawn in the admin page from
 * the link, so nothing is stored here but the code, the scan count and the signups.
 * Guarded with can:campaigns.view / manage.
 */
class ContractorCampaignController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = ContractorCampaign::query()->withCount('contractors')->latest()->get();

        return response()->json(['data' => $rows->map->toArrayFlat()->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120']]);

        $campaign = ContractorCampaign::create(['name' => trim($data['name']), 'code' => $this->newCode()]);

        return response()->json(['message' => 'QR code created.', 'data' => $campaign->loadCount('contractors')->toArrayFlat()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $campaign = ContractorCampaign::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        $campaign->fill($data)->save();

        return response()->json(['message' => 'QR code updated.', 'data' => $campaign->loadCount('contractors')->toArrayFlat()]);
    }

    public function destroy(string $id): JsonResponse
    {
        $campaign = ContractorCampaign::withCount('contractors')->findOrFail($id);

        if ($campaign->contractors_count > 0) {
            throw ValidationException::withMessages([
                'campaign' => 'Contractors signed up through this QR code. Switch it off instead of deleting it.',
            ]);
        }
        $campaign->delete();

        return response()->json(['message' => 'QR code removed.', 'data' => []]);
    }

    /** A short code people can't guess one from the other. */
    private function newCode(): string
    {
        do {
            $code = Str::lower(Str::random(8));
        } while (ContractorCampaign::where('code', $code)->exists());

        return $code;
    }
}
