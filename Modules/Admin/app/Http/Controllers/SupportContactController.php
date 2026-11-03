<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\SupportContact;

class SupportContactController extends Controller
{
    private const MAX_CONTACTS = 20;

    public function index(): JsonResponse
    {
        $rows = SupportContact::query()->orderBy('type')->orderBy('sort_order')->orderBy('created_at')->get();

        return response()->json(['data' => $rows->map->toArrayFlat()->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        if (SupportContact::count() >= self::MAX_CONTACTS) {
            throw ValidationException::withMessages(['value' => 'You can keep up to ' . self::MAX_CONTACTS . ' contacts.']);
        }

        $contact = DB::transaction(function () use ($data) {
            $this->assertUnique($data['type'], $data['value']);
            $data['sort_order'] = (int) SupportContact::where('type', $data['type'])->max('sort_order') + 1;
            $contact = SupportContact::create($data);
            $this->singleActiveWhatsapp($contact);

            return $contact;
        });

        return response()->json(['message' => 'Contact added.', 'data' => $contact->refresh()->toArrayFlat()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $contact = SupportContact::findOrFail($id);
        $data = $this->validated($request, $contact->type);

        DB::transaction(function () use ($contact, $data) {
            $this->assertUnique($contact->type, $data['value'] ?? $contact->value, $contact->id);
            $contact->fill($data)->save();
            $this->singleActiveWhatsapp($contact);
        });

        return response()->json(['message' => 'Contact updated.', 'data' => $contact->refresh()->toArrayFlat()]);
    }

    public function destroy(string $id): JsonResponse
    {
        SupportContact::findOrFail($id)->delete();

        return response()->json(['message' => 'Contact removed.', 'data' => []]);
    }

    /**
     * On create the type is required. On update it is fixed, so only value, label and active can change.
     */
    private function validated(Request $request, ?string $type = null): array
    {
        $creating = $type === null;
        $type ??= $request->input('type');

        $valueRules = match ($type) {
            'email' => ['email:rfc', 'max:120'],
            'phone' => ['regex:/^\+?[0-9\s\-()]{7,20}$/'],
            'whatsapp' => ['regex:/^\+?[0-9\s\-()]{8,20}$/'],
            default => ['string', 'max:120'],
        };

        $data = $request->validate([
            'type' => $creating ? ['required', 'in:' . implode(',', SupportContact::TYPES)] : ['prohibited'],
            'label' => ['sometimes', 'nullable', 'string', 'max:60'],
            'value' => array_merge($creating ? ['required'] : ['sometimes', 'required'], ['string'], $valueRules),
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'value.regex' => $type === 'whatsapp'
                ? 'Enter the WhatsApp number with country code, for example +2348012345678.'
                : 'Enter a valid phone number.',
            'value.email' => 'Enter a valid email address.',
            'type.prohibited' => 'The type of a contact cannot be changed. Remove it and add a new one.',
        ]);

        if (isset($data['value'])) {
            $data['value'] = $type === 'email'
                ? strtolower(trim($data['value']))
                : preg_replace('/[\s\-()]+/', '', trim($data['value']));
        }
        if (array_key_exists('label', $data)) {
            $data['label'] = filled($data['label']) ? trim($data['label']) : null;
        }

        return $data;
    }

    private function assertUnique(string $type, string $value, ?string $ignoreId = null): void
    {
        $exists = SupportContact::where('type', $type)->where('value', $value)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();

        if ($exists) {
            throw ValidationException::withMessages(['value' => 'That contact is already in the list.']);
        }
    }

    /** The floating WhatsApp button needs exactly one number, so activating one switches the others off. */
    private function singleActiveWhatsapp(SupportContact $contact): void
    {
        if ($contact->type === 'whatsapp' && $contact->is_active) {
            SupportContact::where('type', 'whatsapp')->where('id', '!=', $contact->id)->update(['is_active' => false]);
        }
    }
}
