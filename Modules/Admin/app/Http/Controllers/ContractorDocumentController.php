<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Admin\Models\Contractor;
use Modules\Admin\Models\ContractorDocument;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin: a contractor's ID, insurance and licence files. Kept on the private disk and only
 * served through this controller, so nobody can open them by guessing a URL.
 */
class ContractorDocumentController extends Controller
{
    public function store(Request $request, string $contractorId): JsonResponse
    {
        $contractor = Contractor::findOrFail($contractorId);

        $request->validate([
            'kind' => ['required', Rule::in(ContractorDocument::KINDS)],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ], ['file.max' => 'The file must be 5 MB or smaller.', 'file.mimes' => 'Use a JPG, PNG or PDF file.']);

        $file = $request->file('file');
        $doc = $contractor->documents()->create([
            'kind' => $request->input('kind'),
            'path' => $file->store('contractors/' . $contractor->id, 'local'),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 190),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ]);

        return response()->json(['message' => 'Document added.', 'data' => $doc->toArrayFlat()], 201);
    }

    public function download(string $contractorId, string $id): StreamedResponse
    {
        $doc = ContractorDocument::where('contractor_id', $contractorId)->findOrFail($id);
        abort_unless(Storage::disk('local')->exists($doc->path), 404);

        return Storage::disk('local')->response($doc->path, $doc->original_name, [
            'Content-Type' => $doc->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Mark a document as checked. */
    public function update(Request $request, string $contractorId, string $id): JsonResponse
    {
        $doc = ContractorDocument::where('contractor_id', $contractorId)->findOrFail($id);
        $data = $request->validate(['verified' => ['required', 'boolean']]);
        $doc->update($data);

        return response()->json(['message' => $doc->verified ? 'Document marked as checked.' : 'Check removed.', 'data' => $doc->toArrayFlat()]);
    }

    public function destroy(string $contractorId, string $id): JsonResponse
    {
        $doc = ContractorDocument::where('contractor_id', $contractorId)->findOrFail($id);
        Storage::disk('local')->delete($doc->path);
        $doc->delete();

        return response()->json(['message' => 'Document removed.', 'data' => []]);
    }
}
