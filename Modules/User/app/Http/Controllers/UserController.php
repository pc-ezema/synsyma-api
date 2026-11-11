<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\SupportContact;
use Modules\Chat\Models\DeviceToken;
use Modules\User\Models\AccountDeletion;

class UserController extends Controller
{
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'reason' => ['nullable', 'in:' . implode(',', array_keys(AccountDeletion::REASONS))],
            'comment' => ['nullable', 'string', 'max:500'],
            'platform' => ['nullable', 'in:android,ios,web'],
        ], ['password.required' => 'Enter your password to confirm.']);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }

        $deletion = DB::transaction(function () use ($user, $data, $request) {          // NEW: keep the result
            $d = AccountDeletion::create([                                              // NEW: $d
                'user_id' => $user->id,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->name ?? 'Unknown'),
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'reason' => $data['reason'] ?? null,
                'comment' => filled($data['comment'] ?? null) ? trim($data['comment']) : null,
                'platform' => $data['platform'] ?? null,
                'ip_address' => $request->ip(),
                'account_created_at' => $user->created_at,
            ]);

            DeviceToken::where('user_id', $user->id)->delete(); // stop pushes to their phone
            $user->tokens()->delete();                          // sign out everywhere
            // $user->delete();

            return $d;                                          // NEW
        });

        \Modules\Admin\Support\AdminNotifier::deletion($deletion);   // NEW: tells the admin team (never throws)

        return response()->json(['message' => 'Your account has been deleted.', 'data' => []]);
    }

    public function contacts(): JsonResponse
    {
        $rows = SupportContact::query()->where('is_active', true)
            ->orderBy('type')->orderBy('sort_order')->orderBy('created_at')->get();

        return response()->json(['data' => $rows->map(fn (SupportContact $c) => [
            'id' => $c->id,
            'type' => $c->type,
            'label' => $c->label,
            'value' => $c->value,
        ])->values()]);
    }
}
