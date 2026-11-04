<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Admin\Models\Service;
use Modules\Admin\Models\ServiceCategory;

/**
 * App side: what customers browse. Only active categories and services are shown.
 *
 * Premium and Regular rows of the same service (same category and name) are grouped into one
 * service with `options`, which is how the app shows them: one page, a choice between tiers.
 */
class CatalogController extends Controller
{
    private const TIER_ORDER = ['premium' => 0, 'regular' => 1];

    public function categories(): JsonResponse
    {
        $rows = ServiceCategory::query()
            ->where('is_active', true)
            ->withCount(['services as services_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->filter(fn (ServiceCategory $c) => $c->services_count > 0) // nothing to show in an empty category
            ->values();

        return response()->json(['data' => $rows->map(fn (ServiceCategory $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'icon' => $c->icon,
            'description' => $c->description,
            'services_count' => $c->services_count,
        ])->values()]);
    }

    /** GET /services?category=<slug>&search=<text> */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $q = $this->activeServices()->with('category');

        if ($slug = trim((string) $request->query('category'))) {
            $q->whereHas('category', fn ($c) => $c->where('slug', $slug));
        }
        if ($s = trim((string) $request->query('search'))) {
            $q->where('name', 'like', '%' . addcslashes($s, '%_\\') . '%');
        }

        $rows = $q->limit(300)->get()->sortBy([
            fn (Service $a, Service $b) => $a->category->sort_order <=> $b->category->sort_order,
            fn (Service $a, Service $b) => $a->sort_order <=> $b->sort_order,
            fn (Service $a, Service $b) => strcasecmp($a->name, $b->name),
        ])->values();

        return response()->json(['data' => $this->group($rows)->values()]);
    }

    /** GET /services/{id}: the id of any option returns the whole service with all its options. */
    public function show(string $id): JsonResponse
    {
        $service = $this->activeServices()->with('category')->findOrFail($id);

        $siblings = $this->activeServices()->with('category')
            ->where('category_id', $service->category_id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($service->name)])
            ->get();

        return response()->json(['data' => $this->card($this->orderOptions($siblings))]);
    }

    private function activeServices()
    {
        return Service::query()
            ->where('is_active', true)
            ->whereHas('category', fn ($c) => $c->where('is_active', true));
    }

    private function group(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (Service $s) => $s->category_id . '|' . mb_strtolower($s->name))
            ->map(fn (Collection $g) => $this->card($this->orderOptions($g)));
    }

    private function orderOptions(Collection $options): Collection
    {
        return $options->sortBy(fn (Service $s) => self::TIER_ORDER[$s->tier] ?? 9)->values();
    }

    private function card(Collection $options): array
    {
        /** @var Service $first */
        $first = $options->first();

        $priced = $options->filter(fn (Service $s) => $s->price_type !== 'quote' && $s->base_price !== null);
        $cheapest = $priced->sortBy(fn (Service $s) => (float) $s->base_price)->first();

        $fromLabel = match (true) {
            $cheapest === null => 'Get a quote',
            $options->count() > 1 => 'From ' . $cheapest->basePriceLabel(),
            default => $first->priceLabel(),
        };

        return [
            'id' => $first->id,
            'name' => $first->name,
            'slug' => Str::slug($first->name),
            'category_id' => $first->category_id,
            'category_name' => $first->category?->name,
            'category_slug' => $first->category?->slug,
            'description' => $options->pluck('description')->filter()->first(),
            'image_url' => $options->map(fn (Service $s) => $s->imageUrl())->filter()->first(), // the first option that has a photo
            'from_label' => $fromLabel,
            'home_protection' => $options->contains(fn (Service $s) => $s->home_protection),
            'options' => $options->map(fn (Service $s) => [
                'id' => $s->id,
                'tier' => $s->tier,
                'tier_label' => $s->tier ? ucfirst($s->tier) : null,
                'price_type' => $s->price_type,
                'currency' => $s->currency,
                'base_price' => $s->base_price !== null ? (float) $s->base_price : null,
                'included_hours' => $s->included_hours,
                'price_label' => $s->priceLabel(),
                'additional_label' => $s->additionalLabel(),
                'parts_note' => $s->parts_tbd ? 'Fixes with parts replacement: TBD' : null,
                'team_size' => $s->team_size,
                'description' => $s->description,
            ])->values()->all(),
        ];
    }
}
