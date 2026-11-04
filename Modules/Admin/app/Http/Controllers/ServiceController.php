<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\Service;

/**
 * Admin: the services customers can book, with their prices.
 * Routes are guarded with can:services.view / create / update / delete.
 */
class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'tier' => ['sometimes', 'nullable', 'in:' . implode(',', Service::TIERS)],
            'is_active' => ['sometimes', 'nullable', 'in:0,1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);

        $q = Service::query()->with('category')->orderBy('sort_order')->orderBy('name');

        if ($s = trim((string) $request->query('search'))) {
            $q->where('name', 'like', '%' . addcslashes($s, '%_\\') . '%');
        }
        if ($c = $request->query('category_id')) {
            $q->where('category_id', $c);
        }
        if ($t = $request->query('tier')) {
            $q->where('tier', $t);
        }
        if (in_array($request->query('is_active'), ['0', '1', 0, 1], true)) {
            $q->where('is_active', (bool) $request->query('is_active'));
        }

        $page = $q->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'data' => collect($page->items())->map->toArrayFlat()->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => Service::with('category')->findOrFail($id)->toArrayFlat()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['name'], $data['tier'] ?? null);
        $data['sort_order'] ??= (int) Service::where('category_id', $data['category_id'])->max('sort_order') + 1;

        $service = Service::create($data);

        return response()->json(['message' => 'Service added.', 'data' => $service->load('category')->toArrayFlat()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $service = Service::findOrFail($id);
        $service->fill($this->validated($request, $service))->save(); // the slug stays the same so links keep working

        return response()->json(['message' => 'Service updated.', 'data' => $service->load('category')->toArrayFlat()]);
    }

    public function destroy(string $id): JsonResponse
    {
        Service::findOrFail($id)->delete(); // soft delete, so past bookings keep their service

        return response()->json(['message' => 'Service removed.', 'data' => []]);
    }

    /** POST .../{id}/image  (multipart field "image"). */
    public function uploadImage(Request $request, string $id): JsonResponse
    {
        $request->validate(
            ['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']],
            ['image.max' => 'The photo must be 4 MB or smaller.', 'image.mimes' => 'Use a JPG, PNG or WebP photo.', 'image.image' => 'Choose a photo file.'],
        );

        $row = Service::findOrFail($id);
        $row->replaceImage($request->file('image'));

        return response()->json(['message' => 'Photo saved.', 'data' => $row->load('category')->toArrayFlat()]);
    }

    /** DELETE .../{id}/image */
    public function removeImage(string $id): JsonResponse
    {
        $row = Service::findOrFail($id);
        $row->removeImage();

        return response()->json(['message' => 'Photo removed.', 'data' => $row->load('category')->toArrayFlat()]);
    }

    private function validated(Request $request, ?Service $service = null): array
    {
        $creating = $service === null;
        $required = $creating ? ['required'] : ['sometimes', 'required'];

        $data = $request->validate([
            'category_id' => array_merge($required, ['uuid', Rule::exists('service_categories', 'id')->whereNull('deleted_at')]),
            'name' => array_merge($required, ['string', 'min:2', 'max:120']),
            'tier' => ['sometimes', 'nullable', 'in:' . implode(',', Service::TIERS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price_type' => array_merge($required, ['in:' . implode(',', Service::PRICE_TYPES)]),
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'base_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'included_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'additional_hour_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'team_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
            'parts_tbd' => ['sometimes', 'boolean'],
            'home_protection' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ], [
            'category_id.exists' => 'Choose a category that exists.',
        ]);

        // The price type decides which price fields apply. Check the final result, not just what was sent.
        $final = array_merge($service?->only(['price_type', 'base_price', 'included_hours']) ?? [], $data);
        $type = $final['price_type'] ?? null;

        if ($type === 'quote') {
            $data['base_price'] = $data['included_hours'] = $data['additional_hour_price'] = null;
        } elseif ($type !== null) {
            if (($final['base_price'] ?? null) === null) {
                throw ValidationException::withMessages(['base_price' => 'Enter the price.']);
            }
            if ($type === 'hourly') {
                if (($final['included_hours'] ?? null) === null) {
                    throw ValidationException::withMessages(['included_hours' => 'Enter how many hours the price covers.']);
                }
            } else { // fixed
                $data['included_hours'] = $data['additional_hour_price'] = null;
            }
        }

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        if (array_key_exists('description', $data)) {
            $data['description'] = filled($data['description']) ? trim($data['description']) : null;
        }

        return $data;
    }

    private function uniqueSlug(string $name, ?string $tier): string
    {
        $base = Str::slug(trim($name . ' ' . ($tier ?? ''))) ?: 'service';
        $slug = $base;
        $i = 2;

        while (Service::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
