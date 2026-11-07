<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\PromoBanner;
use Modules\Admin\Models\Service;
use Modules\Admin\Models\ServiceCategory;
use Modules\Admin\Support\Promos;

/** Admin: home-screen banners. Guarded with can:banners.view / create / update / delete. */
class AdminPromoBannerController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = PromoBanner::query()->orderBy('sort_order')->orderByDesc('created_at')->get();

        return response()->json(['data' => $rows->map->toArrayFlat()->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['sort_order'] ??= (int) PromoBanner::max('sort_order') + 1;
        $banner = PromoBanner::create($data);

        return response()->json(['message' => 'Banner added.', 'data' => $banner->toArrayFlat()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $banner = PromoBanner::findOrFail($id);
        $banner->update($this->validated($request, true));

        return response()->json(['message' => 'Banner saved.', 'data' => $banner->toArrayFlat()]);
    }

    public function destroy(string $id): JsonResponse
    {
        $banner = PromoBanner::findOrFail($id);
        $banner->removeImage();
        $banner->delete();

        return response()->json(['message' => 'Banner removed.', 'data' => []]);
    }

    public function uploadImage(Request $request, string $id): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096']], ['image.max' => 'The photo must be 4 MB or smaller.']);
        $banner = PromoBanner::findOrFail($id);
        $banner->replaceImage($request->file('image'));

        return response()->json(['message' => 'Photo saved.', 'data' => $banner->toArrayFlat()]);
    }

    public function removeImage(string $id): JsonResponse
    {
        $banner = PromoBanner::findOrFail($id);
        $banner->removeImage();

        return response()->json(['message' => 'Photo removed.', 'data' => $banner->toArrayFlat()]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? ['sometimes', 'required'] : ['required'];

        $data = $request->validate([
            'title' => array_merge($req, ['string', 'min:2', 'max:80']),
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:160'],
            'link_type' => ['sometimes', Rule::in(['none', 'service', 'category'])],
            'link_value' => ['sometimes', 'nullable', 'string', 'max:80'],
            'promo_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]{3,30}$/'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['promo_code.regex' => 'Use 3 to 30 letters, numbers, dashes or underscores.', 'ends_on.after_or_equal' => 'The last day must be on or after the first day.']);

        foreach (['title', 'subtitle'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = trim($data[$k]) ?: null;
            }
        }
        if (array_key_exists('promo_code', $data)) {
            $data['promo_code'] = Promos::normalize($data['promo_code']);
        }

        if (($data['link_type'] ?? null) === 'service' && ! Service::where('id', $data['link_value'] ?? '')->exists()) {
            throw ValidationException::withMessages(['link_value' => 'Choose a service.']);
        }
        if (($data['link_type'] ?? null) === 'category' && ! ServiceCategory::where('slug', $data['link_value'] ?? '')->exists()) {
            throw ValidationException::withMessages(['link_value' => 'Choose a category.']);
        }
        if (($data['link_type'] ?? null) === 'none') {
            $data['link_value'] = null;
        }

        return $data;
    }
}