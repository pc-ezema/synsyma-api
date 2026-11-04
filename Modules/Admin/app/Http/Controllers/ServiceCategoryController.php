<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\ServiceCategory;

/**
 * Admin: service categories (Cleaning, Repairs, Outdoors...).
 * Routes are guarded with can:categories.view / create / update / delete.
 */
class ServiceCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = ServiceCategory::query()->withCount('services')
            ->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $rows->map->toArrayFlat()->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['sort_order'] ??= (int) ServiceCategory::max('sort_order') + 1;

        $category = ServiceCategory::create($data);

        return response()->json([
            'message' => 'Category added.',
            'data' => $category->loadCount('services')->toArrayFlat(),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $category = ServiceCategory::findOrFail($id);
        $category->fill($this->validated($request, $category))->save(); // the slug stays the same so links keep working

        return response()->json([
            'message' => 'Category updated.',
            'data' => $category->loadCount('services')->toArrayFlat(),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $category = ServiceCategory::findOrFail($id);

        if ($category->services()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'This category still has services. Move or remove them first.',
            ]);
        }

        $category->delete();

        return response()->json(['message' => 'Category removed.', 'data' => []]);
    }

    private function validated(Request $request, ?ServiceCategory $category = null): array
    {
        $required = $category ? ['sometimes', 'required'] : ['required'];

        $data = $request->validate([
            'name' => array_merge($required, [
                'string', 'min:2', 'max:80',
                Rule::unique('service_categories', 'name')->whereNull('deleted_at')->ignore($category?->id),
            ]),
            'icon' => ['sometimes', 'nullable', 'string', 'max:40', 'alpha_dash'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ], ['name.unique' => 'A category with that name already exists.']);

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        foreach (['icon', 'description'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = filled($data[$key]) ? trim($data[$key]) : null;
            }
        }

        return $data;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 2;

        while (ServiceCategory::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
