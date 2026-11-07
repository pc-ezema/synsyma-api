<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Admin\Models\PromoBanner;
use Modules\Admin\Models\Service;
use Modules\Admin\Models\ServiceCategory;

/** The signed-in customer's home-screen banners. */
class BannerController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = PromoBanner::live()->get()->filter(function (PromoBanner $b) {
            // Hide a banner whose link points at something switched off, so a tap never lands on "not available".
            return match ($b->link_type) {
                'service' => Service::where('id', $b->link_value)->where('is_active', true)->exists(),
                'category' => ServiceCategory::where('slug', $b->link_value)->where('is_active', true)->exists(),
                default => true,
            };
        });

        return response()->json(['data' => $rows->map->toArrayFlat()->values()]);
    }
}