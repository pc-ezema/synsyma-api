<?php

namespace Modules\User\Http\Controllers;


use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\Address;
use Modules\Admin\Models\Contractor;
use Modules\Admin\Support\ContractorRules;

/** The signed-in customer's saved addresses. */
class AddressController extends Controller
{
    use ContractorRules; // reuses the CA / US / GB postal code check

    private const MAX = 10;

    public function index(Request $request): JsonResponse
    {
        $rows = Address::where('user_id', $request->user()->id)->orderByDesc('is_default')->latest()->get();

        return response()->json(['data' => $rows->map->toArrayFlat()->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (Address::where('user_id', $user->id)->count() >= self::MAX) {
            throw ValidationException::withMessages(['address_line1' => 'You can save up to ' . self::MAX . ' addresses. Remove one first.']);
        }

        $data = $this->clean($request, true);

        $address = DB::transaction(function () use ($user, $data) {
            $first = ! Address::where('user_id', $user->id)->exists();
            if ($first || ! empty($data['is_default'])) {
                Address::where('user_id', $user->id)->update(['is_default' => false]);
                $data['is_default'] = true;
            }

            return Address::create($data + ['user_id' => $user->id]);
        });

        return response()->json(['message' => 'Address saved.', 'data' => $address->toArrayFlat()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $address = Address::where('user_id', $request->user()->id)->findOrFail($id);
        $data = $this->clean($request, false, $address);

        DB::transaction(function () use ($address, $data, $request) {
            if (! empty($data['is_default'])) {
                Address::where('user_id', $request->user()->id)->where('id', '!=', $address->id)->update(['is_default' => false]);
            }
            $address->fill($data)->save();
        });

        return response()->json(['message' => 'Address updated.', 'data' => $address->fresh()->toArrayFlat()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $address = Address::where('user_id', $request->user()->id)->findOrFail($id);

        DB::transaction(function () use ($address, $request) {
            $wasDefault = $address->is_default;
            $address->delete(); // old bookings keep their own copy of the address
            if ($wasDefault) {
                Address::where('user_id', $request->user()->id)->latest()->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(['message' => 'Address removed.', 'data' => []]);
    }

    private function clean(Request $request, bool $creating, ?Address $current = null): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'country' => [$req, Rule::in(array_keys(Contractor::COUNTRIES))],
            'address_line1' => [$req, 'string', 'min:3', 'max:160'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:160'],
            'city' => [$req, 'string', 'min:2', 'max:80'],
            'region' => [$req, 'string', 'min:2', 'max:80'],
            'postal_code' => [$req, 'string', 'max:12'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:300'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = trim($v) === '' ? null : trim($v);
            }
        }
        if (isset($data['postal_code'])) {
            $data['postal_code'] = strtoupper($data['postal_code']);
        }

        $country = $data['country'] ?? $current?->country;
        $postal = $data['postal_code'] ?? $current?->postal_code;
        if ($country && $postal && ! $this->postalCodeIsValid($country, $postal)) {
            throw ValidationException::withMessages(['postal_code' => 'Enter a valid postal or ZIP code for this country.']);
        }

        return $data;
    }
}