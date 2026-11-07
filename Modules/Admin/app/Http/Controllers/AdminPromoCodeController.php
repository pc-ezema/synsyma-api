<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\PromoCode;
use Modules\Admin\Support\Promos;

/** Admin: promo codes. Guarded with can:promos.view / create / update / delete. */
class AdminPromoCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = PromoCode::query()->latest();

        if ($s = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('code', 'like', '%' . strtoupper($s) . '%')->orWhere('description', 'like', "%{$s}%"));
        }
        $today = now()->utc()->toDateString();
        match ($request->query('state')) {
            'active' => $q->where('is_active', true)->where(fn ($w) => $w->whereNull('starts_on')->orWhere('starts_on', '<=', $today))->where(fn ($w) => $w->whereNull('expires_on')->orWhere('expires_on', '>=', $today)),
            'scheduled' => $q->where('is_active', true)->where('starts_on', '>', $today),
            'expired' => $q->where('is_active', true)->where('expires_on', '<', $today),
            'off' => $q->where('is_active', false),
            default => null,
        };

        $page = $q->paginate(min(max((int) $request->query('per_page', 15), 1), 100));
        $usage = PromoCode::usageFor($page->getCollection()->pluck('code')->all());

        return response()->json([
            'data' => $page->getCollection()->map(fn ($p) => $p->toArrayFlat($usage[$p->code] ?? null))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $promo = PromoCode::create($this->validated($request));

        return response()->json(['message' => 'Promo code added.', 'data' => $promo->toArrayFlat()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $promo = PromoCode::findOrFail($id);
        $data = $this->validated($request, $promo);

        if (isset($data['code']) && $data['code'] !== $promo->code && $promo->everUsed()) {
            throw ValidationException::withMessages(['code' => 'This code has been used on bookings, so it cannot be renamed. Switch it off and add a new one.']);
        }
        $promo->update($data);

        return response()->json(['message' => 'Promo code saved.', 'data' => $promo->toArrayFlat(PromoCode::usageFor([$promo->code])[$promo->code] ?? null)]);
    }

    public function destroy(string $id): JsonResponse
    {
        $promo = PromoCode::findOrFail($id);
        if ($promo->everUsed()) {
            throw ValidationException::withMessages(['code' => 'This code has been used on bookings, so it cannot be removed. Switch it off instead.']);
        }
        $promo->delete();

        return response()->json(['message' => 'Promo code removed.', 'data' => []]);
    }

    private function validated(Request $request, ?PromoCode $promo = null): array
    {
        $req = $promo ? ['sometimes', 'required'] : ['required'];

        $data = $request->validate([
            'code' => array_merge($req, ['string', 'regex:/^[A-Za-z0-9_-]{3,30}$/', Rule::unique('promo_codes', 'code')->ignore($promo?->id)]),
            'description' => ['sometimes', 'nullable', 'string', 'max:160'],
            'type' => array_merge($req, [Rule::in(['percent', 'fixed'])]),
            'value' => array_merge($req, ['numeric', 'gt:0', 'max:10000']),
            'max_discount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:10000'],
            'min_subtotal' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'max:100000'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'max_uses_per_user' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'first_booking_only' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.regex' => 'Use 3 to 30 letters, numbers, dashes or underscores, with no spaces.',
            'code.unique' => 'That code already exists.',
            'expires_on.after_or_equal' => 'The last day must be on or after the first day.',
        ]);

        if (isset($data['code'])) {
            $data['code'] = Promos::normalize($data['code']);
        }
        $type = $data['type'] ?? $promo?->type;
        $value = (float) ($data['value'] ?? $promo?->value);
        if ($type === 'percent' && $value > 100) {
            throw ValidationException::withMessages(['value' => 'A percentage cannot be more than 100.']);
        }
        if ($type === 'fixed') {
            $data['max_discount'] = null; // a cap only makes sense for percent codes
        }
        if (array_key_exists('description', $data)) {
            $data['description'] = filled($data['description']) ? trim($data['description']) : null;
        }

        return $data;
    }
}