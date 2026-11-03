<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Models\AccountDeletion;

class AccountDeletionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reason' => ['sometimes', 'nullable', 'in:' . implode(',', array_keys(AccountDeletion::REASONS))],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);

        $q = AccountDeletion::query()->latest('created_at');

        if ($s = trim((string) $request->query('search'))) {
            $like = '%' . addcslashes($s, '%_\\') . '%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like));
        }
        if ($r = $request->query('reason')) {
            $q->where('reason', $r);
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

    public function summary(): JsonResponse
    {
        return response()->json(['data' => [
            'total' => AccountDeletion::count(),
            'last_30_days' => AccountDeletion::where('created_at', '>=', now()->subDays(30))->count(),
            'last_7_days' => AccountDeletion::where('created_at', '>=', now()->subDays(7))->count(),
        ]]);
    }
}
