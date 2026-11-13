<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\Contractor;
use Modules\Admin\Support\ContractorNotifier;
use Modules\Admin\Support\ContractorRules;
use Modules\Admin\Support\ContractorVerificationRules;

/**
 * Admin: contractors (companies and individuals).
 * Guarded with permission:contractors.view / create / update / delete. Background-check results
 * need contractors.verify (own route).
 */
class ContractorController extends Controller
{
    use ContractorRules;
    use ContractorVerificationRules;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(Contractor::STATUSES)],
            'type' => ['sometimes', 'nullable', Rule::in(Contractor::TYPES)],
            'country' => ['sometimes', 'nullable', Rule::in(array_keys(Contractor::COUNTRIES))],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'campaign_id' => ['sometimes', 'nullable', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);

        $q = Contractor::query()->with('campaign')->withCount('categories')->latest();

        if ($s = trim((string) $request->query('search'))) {
            $like = '%' . addcslashes($s, '%_\\') . '%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('contact_name', 'like', $like)
                ->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('city', 'like', $like));
        }
        foreach (['status', 'type', 'country', 'campaign_id'] as $f) {
            if ($v = $request->query($f)) {
                $q->where($f, $v);
            }
        }
        if ($c = $request->query('category_id')) {
            $q->whereHas('categories', fn ($w) => $w->where('service_categories.id', $c));
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

    /** Counts for the tabs at the top of the page. */
    public function summary(): JsonResponse
    {
        $counts = Contractor::query()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return response()->json(['data' => [
            'pending' => (int) ($counts['pending'] ?? 0),
            'approved' => (int) ($counts['approved'] ?? 0),
            'suspended' => (int) ($counts['suspended'] ?? 0),
            'rejected' => (int) ($counts['rejected'] ?? 0),
            'total' => (int) $counts->sum(),
        ]]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->load($id)->toArrayFlat(true)]);
    }

    /**
     * An admin adds a contractor directly. They start as pending, the same as an applicant, and are
     * approved once the ID, both agreements and the background check are on record.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->contractorRules());
        $data = $this->cleanContractorData($data);
        $this->checkPostalCode($data);

        $extra = $request->validate([
            'commission_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $ver = $request->validate($this->verificationRules());

        $contractor = DB::transaction(function () use ($request, $data, $extra, $ver) {
            $contractor = Contractor::create(collect($data)->except(['category_ids', 'areas'])->all() + $extra + [
                'id_type' => $ver['id_type'] ?? null,
                'social_links' => $this->cleanSocialLinks($ver['social_links'] ?? null),
                'status' => 'pending',
                'source' => 'admin',
            ] + $this->consentAttributes($request, null, 'admin'));
            $this->syncContractorLinks($contractor, $data);

            return $contractor;
        });

        return response()->json([
            'message' => 'Contractor added. Upload their ID and finish the verification, then approve them.',
            'data' => $this->load($contractor->id)->toArrayFlat(true),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);

        $data = $request->validate($this->contractorRules(true, $contractor->id) + [
            'commission_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $data = $this->cleanContractorData($data);
        $this->checkPostalCode($data + ['country' => $contractor->country, 'postal_code' => $contractor->postal_code]);

        DB::transaction(function () use ($contractor, $data) {
            $contractor->fill(collect($data)->except(['category_ids', 'areas'])->all())->save();
            $this->syncContractorLinks($contractor, $data);
        });

        return response()->json(['message' => 'Contractor updated.', 'data' => $this->load($contractor->id)->toArrayFlat(true)]);
    }

    /**
     * ID type, social links and the two agreements (recorded on the contractor's behalf).
     * The background-check result has its own route, guarded by contractors.verify.
     */
    public function updateVerification(Request $request, string $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);

        $data = $request->validate($this->verificationRules());

        $attrs = $this->consentAttributes($request, $contractor, 'admin');

        if (array_key_exists('id_type', $data)) {
            $attrs['id_type'] = $data['id_type'];
        }
        if (array_key_exists('social_links', $data)) {
            $attrs['social_links'] = $this->cleanSocialLinks($data['social_links']);
        }

        $contractor->update($attrs);

        return response()->json(['message' => 'Verification updated.', 'data' => $this->load($contractor->id)->toArrayFlat(true)]);
    }

    /** The background-check result and private notes. Guarded by contractors.verify on the route. */
    public function updateBackgroundCheck(Request $request, string $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);

        $data = $request->validate([
            'background_status' => ['required', Rule::in(Contractor::BACKGROUND_STATUSES)],
            'background_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        if ($data['background_status'] !== 'not_started' && ! $contractor->background_consent_at) {
            throw ValidationException::withMessages(['background_status' => 'Record their consent to a background check first.']);
        }

        $attrs = [
            'background_status' => $data['background_status'],
            'background_checked_at' => in_array($data['background_status'], ['passed', 'failed'], true) ? now() : null,
        ];
        if (array_key_exists('background_notes', $data)) {
            $attrs['background_notes'] = filled($data['background_notes']) ? trim($data['background_notes']) : null;
        }
        $contractor->update($attrs);

        return response()->json(['message' => 'Background check saved.', 'data' => $this->load($contractor->id)->toArrayFlat(true)]);
    }

    /** Approve, reject or suspend. A reason is needed to reject or suspend. Approval needs the verification complete. */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $contractor = $this->load($id);

        $data = $request->validate([
            'status' => ['required', Rule::in(Contractor::STATUSES)],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        if (in_array($data['status'], ['rejected', 'suspended'], true) && ! filled($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'Give a short reason.']);
        }

        if ($data['status'] === 'approved') {
            $v = $contractor->verification();
            if (! $v['ready']) {
                throw ValidationException::withMessages(['status' => "Can't approve yet. Still needed: " . implode(', ', $v['missing']) . '.']);
            }
        }

        $previous = $contractor->status; // read before it changes, so the right notification goes out
        $contractor->status = $data['status'];
        $reason = trim((string) ($data['reason'] ?? ''));
        $contractor->status_reason = $data['status'] === 'approved' || $reason === '' ? null : $reason;
        if ($data['status'] === 'approved') {
            $contractor->approved_at ??= now();
        }
        $contractor->save();

        if ($previous !== $data['status']) {
            $event = match ($data['status']) {
                'approved' => $previous === 'suspended' ? 'reinstated' : 'approved',
                'rejected' => 'rejected',
                'suspended' => 'suspended',
                default => null,
            };
            if ($event) {
                ContractorNotifier::send($contractor, $event, $contractor->status_reason);
            }
        }

        $label = ['approved' => 'approved', 'rejected' => 'rejected', 'suspended' => 'suspended', 'pending' => 'set back to pending'][$data['status']];

        return response()->json(['message' => "Contractor {$label}.", 'data' => $this->load($contractor->id)->toArrayFlat(true)]);
    }

    public function destroy(string $id): JsonResponse
    {
        $contractor = Contractor::with('documents')->findOrFail($id);

        // Bookings will block this once they exist. Documents are removed with the contractor.
        foreach ($contractor->documents as $doc) {
            Storage::disk('local')->delete($doc->path);
        }
        $contractor->delete();

        return response()->json(['message' => 'Contractor removed.', 'data' => []]);
    }

    private function load(string $id): Contractor
    {
        return Contractor::with(['campaign', 'categories', 'areas', 'documents'])->findOrFail($id);
    }

    private function checkPostalCode(array $data): void
    {
        if (isset($data['postal_code'], $data['country']) && ! $this->postalCodeIsValid($data['country'], $data['postal_code'])) {
            throw ValidationException::withMessages(['postal_code' => 'Enter a valid postal or ZIP code for the country.']);
        }
    }
}