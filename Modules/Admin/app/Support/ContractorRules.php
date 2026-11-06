<?php

namespace Modules\Admin\Support;

use Illuminate\Validation\Rule;
use Modules\Admin\Models\Contractor;

/**
 * Validation rules shared by the admin form and the public sign-up form.
 * Each controller still calls $request->validate() itself.
 */
trait ContractorRules
{
    protected function contractorRules(bool $partial = false, ?string $ignoreId = null): array
    {
        $req = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'type' => array_merge($req, [Rule::in(Contractor::TYPES)]),
            'name' => array_merge($req, ['string', 'min:2', 'max:160']),
            'contact_name' => array_merge($req, ['string', 'min:2', 'max:120']),
            'email' => array_merge($req, [
                'email:rfc', 'max:190',
                Rule::unique('contractors', 'email')->whereNull('deleted_at')->ignore($ignoreId),
            ]),
            'phone' => array_merge($req, ['string', 'regex:/^[+0-9 ()\-]{7,20}$/']),
            'country' => array_merge($req, [Rule::in(array_keys(Contractor::COUNTRIES))]),
            'address_line1' => array_merge($req, ['string', 'min:3', 'max:160']),
            'city' => array_merge($req, ['string', 'min:2', 'max:80']),
            'region' => array_merge($req, ['string', 'min:2', 'max:80']),
            'postal_code' => array_merge($req, ['string', 'max:12']),
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'about' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category_ids' => ['sometimes', 'array', 'max:50'],
            'category_ids.*' => ['uuid', Rule::exists('service_categories', 'id')->whereNull('deleted_at')],
            'areas' => ['sometimes', 'array', 'max:30'],
            'areas.*.region' => ['required', 'string', 'min:2', 'max:80'],
            'areas.*.city' => ['nullable', 'string', 'max:80'],
        ];
    }

    /** Postal code shape for the three countries we serve, so tax can be worked out later. */
    protected function postalCodeIsValid(string $country, string $code): bool
    {
        $code = strtoupper(trim($code));

        return match ($country) {
            'CA' => (bool) preg_match('/^[ABCEGHJ-NPRSTVXY]\d[A-Z] ?\d[A-Z]\d$/', $code),
            'US' => (bool) preg_match('/^\d{5}(-\d{4})?$/', $code),
            'GB' => (bool) preg_match('/^[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}$/', $code),
            default => false,
        };
    }

    protected function cleanContractorData(array $data): array
    {
        foreach (['name', 'contact_name', 'phone', 'address_line1', 'city', 'region', 'tax_number', 'about'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = filled($data[$k]) ? trim($data[$k]) : null;
            }
        }
        if (isset($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }
        if (isset($data['postal_code'])) {
            $data['postal_code'] = strtoupper(trim($data['postal_code']));
        }

        return $data;
    }

    /** Replaces the contractor's categories and areas. Skip a key to leave it as it is. */
    protected function syncContractorLinks(Contractor $contractor, array $data): void
    {
        if (array_key_exists('category_ids', $data)) {
            $contractor->categories()->sync(array_values(array_unique($data['category_ids'])));
        }

        if (array_key_exists('areas', $data)) {
            $contractor->areas()->delete();
            $seen = [];
            foreach ($data['areas'] as $a) {
                $region = trim($a['region']);
                $city = filled($a['city'] ?? null) ? trim($a['city']) : null;
                $key = mb_strtolower($region . '|' . ($city ?? ''));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $contractor->areas()->create(['country' => $contractor->country, 'region' => $region, 'city' => $city]);
            }
        }
    }
}
