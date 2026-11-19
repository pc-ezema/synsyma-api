<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Admin\Support\AddressSearch;

/** Public: address suggestions for the contractor application form on the website. */
class PublicAddressSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:120'],
            'country' => ['required', Rule::in(AddressSearch::COUNTRIES)],
        ]);

        $rows = AddressSearch::search((string) $request->query('q'), $request->query('country'));

        if ($rows === null) {
            return response()->json(['message' => "Address search isn't available right now.", 'data' => []], 503);
        }

        return response()->json(['data' => $rows]);
    }
}