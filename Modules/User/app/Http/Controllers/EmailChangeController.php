<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Admin\Emails\AccountNotice;
use Modules\Admin\Http\Controllers\AdminUserEmailController;
use Modules\User\Models\UserEmailChange;
use Modules\Chat\Models\DeviceToken;

/**
 * The public page behind the link in the "Confirm your new email" message. No sign-in needed: holding the link,
 * which only reaches the new address, is the proof.
 *
 * GET shows a page with a Confirm button, and POST does the change. Doing it in two steps means an email scanner
 * that opens the link cannot confirm it by accident.
 */
class EmailChangeController extends Controller
{
    public function show(string $token)
    {
        $change = UserEmailChange::where('token_hash', hash('sha256', $token))->first();

        return view('user::email-change', ['state' => $change && $change->isOpen() ? 'ready' : 'invalid', 'email' => $change?->new_email]);
    }

    public function confirm(Request $request, string $token)
    {
        $U = config('auth.providers.users.model');
        $result = DB::transaction(function () use ($token, $U) {
            $change = UserEmailChange::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $change || ! $change->isOpen()) {
                return ['invalid', null, null];
            }
            $user = $U::lockForUpdate()->find($change->user_id);
            if (! $user) {
                return ['invalid', null, null];
            }
            if ($U::whereRaw('lower(email) = ?', [mb_strtolower($change->new_email)])->where('id', '!=', $user->id)->exists()) {
                $change->update(['status' => 'cancelled']);

                return ['taken', $change, $user];
            }

            $user->forceFill([
                'email' => $change->new_email,
                'email_verified_at' => now(),
                'refresh_token' => null,
                'refresh_token_expires_at' => null,
            ])->save();
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete(); // signed out everywhere: they log in again with the new email
            }
            DeviceToken::where('user_id', $user->id)->delete();
            $change->update(['status' => 'confirmed', 'confirmed_at' => now()]);

            return ['done', $change, $user];
        });

        [$state, $change, $user] = $result;

        if ($state === 'done') {
            Log::info('user_email_changed', ['user_id' => $user->id, 'change_id' => $change->id]);
            try {
                $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                Mail::to($change->old_email)->queue(new AccountNotice(
                    $name, 'Account update', 'Your email was changed',
                    'The email on your ' . config('app.name', 'Synsyma') . ' account is now ' . AdminUserEmailController::mask($change->new_email) . '. You have been signed out and will need to sign in with the new email.',
                ));
            } catch (\Throwable $e) {
                Log::warning('Email change notice failed', ['error' => $e->getMessage()]);
            }
        }

        return view('user::email-change', ['state' => $state, 'email' => $change?->new_email]);
    }
}