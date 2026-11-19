<?php

namespace Modules\Admin\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Address suggestions from Geoapify. This is the only place that talks to the provider, so the admin, the
 * mobile app and the landing page all get the same results, and the provider can be swapped here alone.
 * The key comes from the server .env (GEOAPIFY_KEY).
 */
class AddressSearch
{
    public const COUNTRIES = ['CA', 'US', 'GB'];

    /**
     * @return array<int, array<string, mixed>>|null  null means the provider is not available right now
     */
    public static function search(string $text, ?string $country = null): ?array
    {
        $key = config('geoapify.key');
        if (! $key) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) < 3) {
            return [];
        }

        $country = in_array($country, self::COUNTRIES, true) ? $country : null;
        $codes = array_map('strtolower', $country ? [$country] : self::COUNTRIES);

        $cacheKey = 'addr:' . md5(mb_strtolower($text) . '|' . implode(',', $codes));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $res = Http::timeout((int) config('geoapify.timeout', 5))->acceptJson()->get('https://api.geoapify.com/v1/geocode/autocomplete', [
                'text' => $text,
                'apiKey' => $key,
                'format' => 'json',
                'limit' => 6,
                'lang' => 'en',
                'filter' => 'countrycode:' . implode(',', $codes),
            ]);
        } catch (\Throwable $e) {
            // Not report($e): the message contains the request URL, which includes the key.
            Log::warning('Address search failed', ['error' => get_class($e)]);

            return null;
        }

        if (! $res->successful()) {
            Log::warning('Address search failed', ['status' => $res->status()]);

            return null;
        }

        $rows = self::normalise((array) $res->json('results', []));
        Cache::put($cacheKey, $rows, now()->addMinutes(10));

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $results */
    private static function normalise(array $results): array
    {
        $out = [];
        $seen = [];

        foreach ($results as $r) {
            $cc = strtoupper((string) ($r['country_code'] ?? ''));
            if (! in_array($cc, self::COUNTRIES, true)) {
                continue;
            }
            if (in_array($r['result_type'] ?? '', ['country', 'state', 'county'], true)) {
                continue;
            }

            $label = trim((string) ($r['formatted'] ?? ''));
            if ($label === '' || isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;

            $line1 = trim(trim((string) ($r['housenumber'] ?? '')) . ' ' . trim((string) ($r['street'] ?? '')));
            $city = (string) ($r['city'] ?? $r['town'] ?? $r['village'] ?? $r['suburb'] ?? '');

            $out[] = [
                'label' => $label,
                'address_line1' => $line1,
                'city' => trim($city),
                'region' => trim((string) ($r['state'] ?? '')),
                'postal_code' => self::postal($cc, (string) ($r['postcode'] ?? '')),
                'country' => $cc,
                'lat' => isset($r['lat']) ? (float) $r['lat'] : null,
                'lon' => isset($r['lon']) ? (float) $r['lon'] : null,
            ];
        }

        return $out;
    }

    /** The shape our own checks expect: "M5V 2T6", "SW1A 1AA". */
    private static function postal(string $country, string $raw): string
    {
        $p = strtoupper(trim($raw));
        if ($p === '') {
            return '';
        }
        if ($country === 'CA' && preg_match('/^([A-Z]\d[A-Z])\s?(\d[A-Z]\d)$/', $p, $m)) {
            return $m[1] . ' ' . $m[2];
        }
        if ($country === 'GB' && ! str_contains($p, ' ') && strlen($p) >= 5 && strlen($p) <= 7) {
            return substr($p, 0, -3) . ' ' . substr($p, -3);
        }

        return $p;
    }
}