<?php

namespace Modules\Admin\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Admin\Models\Contractor;

/**
 * Shared by the public apply form (PublicJoinController) and the admin ContractorController, so
 * a contractor is checked the same way whoever registers them.
 */
trait ContractorVerificationRules
{
    protected function verificationRules(): array
    {
        $rules = [
            'id_type' => ['sometimes', 'nullable', Rule::in(Contractor::ID_TYPES)],
            'social_links' => ['sometimes', 'nullable', 'array'],
            'tax_ack' => ['sometimes', 'boolean'],
            'background_consent' => ['sometimes', 'boolean'],
        ];
        foreach (Contractor::SOCIAL_KEYS as $key) {
            $rules["social_links.$key"] = ['sometimes', 'nullable', 'url:http,https', 'max:190'];
        }

        return $rules;
    }

    /** Keeps only the known keys and drops empty ones. Returns null when nothing is left. */
    protected function cleanSocialLinks(?array $links): ?array
    {
        $out = [];
        foreach (Contractor::SOCIAL_KEYS as $key) {
            $v = trim((string) ($links[$key] ?? ''));
            if ($v !== '') {
                $out[$key] = $v;
            }
        }

        return $out ?: null;
    }

    /**
     * Timestamps for any agreement that has just been given. An agreement already on record is never
     * overwritten or removed here. $source is 'self' (the contractor ticked it) or 'admin' (an admin
     * recorded it for them).
     */
    protected function consentAttributes(Request $request, ?Contractor $existing, string $source): array
    {
        $out = [];
        if ($request->boolean('tax_ack') && ! $existing?->tax_ack_at) {
            $out['tax_ack_at'] = now();
        }
        if ($request->boolean('background_consent') && ! $existing?->background_consent_at) {
            $out['background_consent_at'] = now();
        }
        if ($out) {
            $out += [
                'consent_version' => Contractor::CONSENT_VERSION,
                'consent_source' => $source,
                'consent_ip' => $source === 'self' ? $request->ip() : null,
                'consent_recorded_by' => $source === 'admin' ? (string) $request->user()?->id : null,
            ];
        }

        return $out;
    }
}