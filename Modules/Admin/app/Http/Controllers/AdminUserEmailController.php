<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Emails\AccountNotice;
use Modules\User\Models\UserEmailChange;

/**
 * Support changing a customer's email (customers cannot do it themselves).
 *
 *   POST   users/{id}/email   (can:users.change_email)  { new_email, reason, password }  start a change
 *   DELETE users/{id}/email   (can:users.change_email)                                    cancel the open one
 *
 * Starting a change does NOT change anything. It emails a confirmation link to the NEW address and a notice to the
 * OLD one. The email only changes when the new address's owner confirms (see EmailChangeController).
 * The admin has to re-enter their own password, and every change is kept on record.
 */
class AdminUserEmailController extends Controller
{
    private const LINK_HOURS = 24;

    public function start(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'new_email' => ['required', 'string', 'email:rfc', 'max:190'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
            'password' => ['required', 'string'],
        ], [
            'new_email.required' => 'Enter the new email.',
            'new_email.email' => 'Enter a valid email address.',
            'reason.required' => 'Give a short reason.',
            'reason.min' => 'Give a short reason.',
            'password.required' => 'Enter your password to confirm.',
        ]);

        $admin = $request->user();
        if (! Hash::check($data['password'], $admin->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }

        $U = config('auth.providers.users.model');
        $user = $U::findOrFail($id);
        $new = mb_strtolower(trim($data['new_email']));

        if ($new === mb_strtolower((string) $user->email)) {
            throw ValidationException::withMessages(['new_email' => 'That is already their email.']);
        }
        if ($U::whereRaw('lower(email) = ?', [$new])->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages(['new_email' => 'Another account already uses that email.']);
        }

        // A new request replaces any earlier one that is still waiting.
        UserEmailChange::where('user_id', $user->id)->where('status', 'pending')->update(['status' => 'cancelled']);

        $plain = Str::random(48);
        $change = UserEmailChange::create([
            'user_id' => $user->id,
            'admin_id' => $admin->id,
            'old_email' => $user->email,
            'new_email' => $new,
            'reason' => trim($data['reason']),
            'token_hash' => hash('sha256', $plain),
            'status' => 'pending',
            'expires_at' => now()->addHours(self::LINK_HOURS),
        ]);

        $app = config('app.name', 'Synsyma');
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        $link = url('/email-change/confirm/' . $plain);

        Mail::to($new)->queue(new AccountNotice(
            $name, 'Confirm your email', 'Confirm your new email',
            'We were asked to change the email on your ' . $app . ' account to this address. Tap the button to confirm it. The link works for ' . self::LINK_HOURS . ' hours. If you did not ask for this, ignore this email and nothing will change.',
            $link, 'Confirm new email',
        ));
        Mail::to($user->email)->queue(new AccountNotice(
            $name, 'Account update', 'Your email is being changed',
            'A request was made to change the email on your ' . $app . ' account to ' . self::mask($new) . '. Nothing changes until that address is confirmed. If you did not ask for this, please contact us now.',
        ));

        Log::info('admin_email_change_started', ['admin_id' => $admin->id, 'user_id' => $user->id, 'change_id' => $change->id]);

        return response()->json([
            'message' => "Confirmation sent to {$new}. The email changes once they confirm it.",
            'data' => ['email_changes' => UserEmailChange::historyFor($user->id)],
        ]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $n = UserEmailChange::where('user_id', $id)->where('status', 'pending')->update(['status' => 'cancelled']);
        Log::info('admin_email_change_cancelled', ['admin_id' => $request->user()->id, 'user_id' => $id, 'count' => $n]);

        return response()->json(['message' => 'Email change cancelled.', 'data' => ['email_changes' => UserEmailChange::historyFor($id)]]);
    }

    /** j***@gmail.com */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1) . '***@' . $domain;
    }
}