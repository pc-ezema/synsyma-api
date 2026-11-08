<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Admin\Models\Contractor;
use Modules\Admin\Models\ContractorPayoutDetail;

/**
 * Admin: where each contractor gets paid.
 * GET needs payouts.view (secrets masked unless the admin also has payouts.manage). PUT / DELETE need payouts.manage.
 */
class AdminPayoutDetailsController extends Controller
{
    public function show(Request $request, string $contractorId): JsonResponse
    {
        $c = Contractor::findOrFail($contractorId);
        $row = ContractorPayoutDetail::where('contractor_id', $c->id)->first();

        return response()->json(['data' => $row ? $row->toArrayFlat($this->canReveal($request)) : null]);
    }

    public function update(Request $request, string $contractorId): JsonResponse
    {
        $c = Contractor::findOrFail($contractorId);

        $request->validate([
            'method' => 'required|in:bank_transfer,etransfer,paypal,other',
            'holder_name' => 'required|string|max:120',
            'bank_name' => 'nullable|string|max:120',
            'country' => 'required_if:method,bank_transfer|nullable|in:CA,US,GB',
            'details' => 'required|array',
        ], [
            'holder_name.required' => 'Enter the name on the account.',
            'country.required_if' => 'Choose the country of the bank account.',
        ]);

        $method = $request->input('method');
        $country = $method === 'bank_transfer' ? $request->input('country') : null;

        // Keep only the fields this method uses, and strip spaces and dashes from numbers.
        $in = (array) $request->input('details');
        $clean = [];
        foreach ($this->fields($method, $country) as $key => $rule) {
            $v = $in[$key] ?? null;
            if (is_string($v)) {
                $v = trim($v);
                if (in_array($key, ['institution_number', 'transit_number', 'routing_number', 'account_number', 'sort_code'], true)) {
                    $v = preg_replace('/[\s-]+/', '', $v);
                }
                if ($key === 'email') {
                    $v = strtolower($v);
                }
            }
            $clean[$key] = $v;
        }
        $request->merge(['details' => $clean]);

        $rules = [];
        foreach ($this->fields($method, $country) as $key => $rule) {
            $rules["details.$key"] = $rule;
        }
        $request->validate($rules, [
            'details.institution_number.regex' => 'The institution number is 3 digits.',
            'details.transit_number.regex' => 'The transit number is 5 digits.',
            'details.routing_number.regex' => 'The routing number is 9 digits.',
            'details.sort_code.regex' => 'The sort code is 6 digits, like 12-34-56.',
            'details.account_number.regex' => $country === 'GB' ? 'A UK account number is 8 digits.' : ($country === 'US' ? 'A US account number is 4 to 17 digits.' : 'A Canadian account number is 7 to 12 digits.'),
            'details.*.required' => 'This field is required.',
        ]);

        $row = ContractorPayoutDetail::firstOrNew(['contractor_id' => $c->id]);
        $row->fill([
            'method' => $method,
            'country' => $country,
            'holder_name' => trim($request->input('holder_name')),
            'bank_name' => $method === 'bank_transfer' ? (trim((string) $request->input('bank_name')) ?: null) : null,
            'details' => $clean,
            'updated_by' => $request->user()?->id,
        ])->save();

        // The log says who changed what, never the numbers themselves.
        Log::info('payout_details.updated', ['contractor_id' => $c->id, 'method' => $method, 'by' => $request->user()?->id]);

        return response()->json(['message' => 'Payout details saved.', 'data' => $row->toArrayFlat(true)]);
    }

    public function destroy(Request $request, string $contractorId): JsonResponse
    {
        $c = Contractor::findOrFail($contractorId);
        ContractorPayoutDetail::where('contractor_id', $c->id)->delete();
        Log::info('payout_details.removed', ['contractor_id' => $c->id, 'by' => $request->user()?->id]);

        return response()->json(['message' => 'Payout details removed.', 'data' => null]);
    }

    private function canReveal(Request $request): bool
    {
        return (bool) $request->user()?->can('payouts.manage');
    }

    /** The fields each method needs, as validation rules. */
    private function fields(string $method, ?string $country): array
    {
        return match ($method) {
            'bank_transfer' => match ($country) {
                'US' => [
                    'routing_number' => ['required', 'regex:/^\d{9}$/'],
                    'account_number' => ['required', 'regex:/^\d{4,17}$/'],
                    'account_type' => ['required', 'in:checking,savings'],
                ],
                'GB' => [
                    'sort_code' => ['required', 'regex:/^\d{6}$/'],
                    'account_number' => ['required', 'regex:/^\d{8}$/'],
                ],
                default => [
                    'institution_number' => ['required', 'regex:/^\d{3}$/'],
                    'transit_number' => ['required', 'regex:/^\d{5}$/'],
                    'account_number' => ['required', 'regex:/^\d{7,12}$/'],
                ],
            },
            'etransfer', 'paypal' => ['email' => ['required', 'email', 'max:190']],
            default => ['instructions' => ['required', 'string', 'max:600']],
        };
    }
}