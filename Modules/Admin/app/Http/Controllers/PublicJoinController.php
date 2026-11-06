<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\Contractor;
use Modules\Admin\Models\ContractorCampaign;
use Modules\Admin\Models\ContractorDocument;
use Modules\Admin\Models\ServiceCategory;
use Modules\Admin\Support\ContractorNotifier;
use Modules\Admin\Support\ContractorRules;

/**
 * Public (no login): the page a QR code or advert opens, where a company or an individual applies.
 * Applications are always saved as "pending" until an admin approves them.
 */
class PublicJoinController extends Controller
{
    use ContractorRules;

    /** Categories and countries for the form. Also counts a scan when a QR code is used. */
    public function options(Request $request): JsonResponse
    {
        $request->validate(['ref' => ['sometimes', 'nullable', 'string', 'max:24', 'alpha_dash']]);

        $campaign = null;
        if ($code = $request->query('ref')) {
            $campaign = ContractorCampaign::where('code', $code)->where('is_active', true)->first();
            $campaign?->increment('scans');
        }

        return response()->json(['data' => [
            'categories' => ServiceCategory::where('is_active', true)->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'icon', 'description'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'icon' => $c->icon, 'description' => $c->description])->values(),
            'countries' => collect(Contractor::COUNTRIES)->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values(),
            'ref_valid' => $campaign !== null,
        ]]);
    }

    public function apply(Request $request): JsonResponse
    {
        // Bots fill every field. A real person never sees this one.
        if (filled($request->input('website'))) {
            return response()->json(['message' => 'Thanks. We will be in touch.', 'data' => []], 201);
        }

        $data = $request->validate($this->contractorRules() + [
            'ref' => ['sometimes', 'nullable', 'string', 'max:24', 'alpha_dash'],
            'agreed' => ['accepted'],
            'documents' => ['sometimes', 'array', 'max:4'],
            'documents.*.kind' => ['required', Rule::in(ContractorDocument::KINDS)],
            'documents.*.file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ], [
            'agreed.accepted' => 'Please confirm the details are correct.',
            'documents.*.file.max' => 'Each file must be 5 MB or smaller.',
            'documents.*.file.mimes' => 'Use JPG, PNG or PDF files.',
            'email.unique' => 'An application with this email already exists.',
        ]);

        $data = $this->cleanContractorData($data);

        if (! $this->postalCodeIsValid($data['country'], $data['postal_code'])) {
            throw ValidationException::withMessages(['postal_code' => 'Enter a valid postal or ZIP code for the country.']);
        }
        if ($data['type'] === 'individual' && ! collect($request->input('documents', []))->contains(fn ($d) => ($d['kind'] ?? null) === 'id')) {
            throw ValidationException::withMessages(['documents' => 'Individuals must upload a photo ID.']);
        }

        $campaign = ! empty($data['ref'])
            ? ContractorCampaign::where('code', $data['ref'])->where('is_active', true)->first()
            : null;

        // CHANGE 1: keep the result of the transaction
        $contractor = DB::transaction(function () use ($request, $data, $campaign) {
            $contractor = Contractor::create(collect($data)->except(['category_ids', 'areas', 'ref', 'agreed', 'documents'])->all() + [
                'status' => 'pending',
                'source' => 'self',
                'campaign_id' => $campaign?->id,
            ]);
            $this->syncContractorLinks($contractor, $data);

            foreach ((array) $request->file('documents', []) as $i => $row) {
                $file = $row['file'] ?? null;
                if (! $file) {
                    continue;
                }
                $contractor->documents()->create([
                    'kind' => $request->input("documents.$i.kind"),
                    'path' => $file->store('contractors/' . $contractor->id, 'local'),
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 190),
                    'mime' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                ]);
            }

            return $contractor; // CHANGE 2: hand it back
        });

        // CHANGE 3: send the emails after everything is saved
        ContractorNotifier::send($contractor, 'applied');   // confirmation to the applicant
        ContractorNotifier::notifyTeam($contractor);        // alert to your team

        return response()->json(['message' => 'Thanks. Your application is in. We will contact you after we review it.', 'data' => []], 201);
    }
}
