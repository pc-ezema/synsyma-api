<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Emails\AdminWelcomeMail;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\AdminDeviceToken;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Modules\Admin\Emails\AdminPasswordChangedMail;

class AdminController extends Controller
{
    public function admins(Request $request): JsonResponse
    {
        $f = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Admin::query()->with(['roles', 'permissions'])->orderBy('name');

        if (! empty($f['search'])) {
            $term = '%'.addcslashes(trim($f['search']), '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        $page = $query->paginate($f['per_page'] ?? 15);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Admin $a) => $this->row($a))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function storeAdmin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique(Admin::class, 'email')],
            'password' => ['nullable', 'string', 'min:8', 'max:100'], // blank = we generate one
            'roles' => ['sometimes', 'array'],
            'roles.*' => [Rule::exists('roles', 'id')->where('guard_name', 'admin')],
        ]);

        $roleNames = Role::where('guard_name', 'admin')->whereIn('id', $data['roles'] ?? [])->pluck('name')->all();

        // Only a super admin can mint another super admin
        if (in_array('super_admin', $roleNames, true) && ! $request->user()->hasRole('super_admin')) {
            throw ValidationException::withMessages(['roles' => 'Only a super admin can assign the super admin role.']);
        }

        $password = ! empty($data['password']) ? $data['password'] : Str::password(14, symbols: false);

        $admin = DB::transaction(function () use ($data, $password, $roleNames) {
            $admin = Admin::create([
                'name' => trim($data['name']),
                'email' => strtolower(trim($data['email'])),
                'password' => Hash::make($password),
                'is_active' => true,
            ]);

            if (! $roleNames) {
                // Default role: exists but has no permissions until a super admin edits it
                $roleNames = [Role::findOrCreate('admin', 'admin')->name];
            }

            $admin->syncRoles($roleNames);

            return $admin;
        });

        $emailSent = $this->sendWelcome($admin->refresh(), $password, $request->user());

        return response()->json([
            'message' => $emailSent
                ? 'Administrator created. Login details were sent to their email.'
                : 'Administrator created, but the email could not be sent.',
            'data' => $this->row($admin) + ['email_sent' => $emailSent],
        ], 201);
    }

    /**
     * Passwords are hashed, so the old one cannot be resent. This sets a NEW temporary password,
     * emails it, and signs the admin out everywhere. If the email fails, the old password is restored
     * so nobody gets locked out with a password nobody knows.
     */
    public function resendCredentials(Request $request, Admin $admin): JsonResponse
    {
        $actor = $request->user();

        if ($actor->is($admin)) {
            abort(403, 'You cannot reset your own login details here.');
        }
        if ($admin->hasRole('super_admin') && ! $actor->hasRole('super_admin')) {
            abort(403, 'Only a super admin can reset a super admin.');
        }
        if (! $admin->is_active) {
            throw ValidationException::withMessages(['admin' => 'This account is disabled. Enable it first.']);
        }

        // At most 3 sends per admin per hour, so this can't be used to flood an inbox
        $key = 'admin-resend:'.$admin->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $mins = (int) ceil(RateLimiter::availableIn($key) / 60);

            return response()->json(['message' => "Login details were already sent a few times. Try again in {$mins} minute".($mins === 1 ? '' : 's').'.'], 429);
        }

        $password = Str::password(14, symbols: false);
        $previousHash = $admin->password;

        $admin->forceFill(['password' => Hash::make($password)])->save();

        try {
            Mail::to($admin->email)->send(new AdminWelcomeMail(
                $admin,
                $password,
                $admin->getRoleNames()->map(fn ($r) => Str::headline($r))->values()->all(),
                $actor->name,
                isReset: true,
            ));
        } catch (\Throwable $e) {
            report($e);
            $admin->forceFill(['password' => $previousHash])->save(); // put the old password back

            return response()->json(['message' => "The email could not be sent, so nothing was changed. Check the mail settings and try again."], 502);
        }

        RateLimiter::hit($key, 3600);

        // Old sessions end now that the password changed
        $admin->tokens()->delete();
        $admin->forceFill(['refresh_token' => null])->save(); // add your refresh-expiry column here if you have one

        return response()->json([
            'message' => 'New login details sent.',
            'data' => $this->row($admin->refresh()),
        ]);
    }

    /** A mail failure must not undo the account, so it is reported instead of thrown. */
    private function sendWelcome(Admin $admin, string $password, Admin $creator): bool
    {
        try {
            Mail::to($admin->email)->send(new AdminWelcomeMail(
                $admin,
                $password,
                $admin->getRoleNames()->map(fn ($r) => Str::headline($r))->values()->all(),
                $creator->name,
            ));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public function updateAdmin(Request $request, Admin $admin): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email:rfc', 'max:190', Rule::unique(Admin::class, 'email')->ignore($admin->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $this->guard($request, $admin, 'disable');
        }

        $admin->fill($data)->save();

        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $this->revokeSessions($admin); // signed out everywhere
        }

        return response()->json(['message' => 'Administrator updated.', 'data' => $this->row($admin->refresh())]);
    }

    public function destroyAdmin(Request $request, Admin $admin): JsonResponse
    {
        $this->guard($request, $admin, 'delete');
        $this->revokeSessions($admin);
        $admin->delete();

        return response()->json(['message' => 'Administrator deleted.']);
    }

    private function guard(Request $request, Admin $admin, string $verb): void
    {
        if ($request->user()->is($admin)) {
            abort(403, "You cannot {$verb} your own account.");
        }
        if ($admin->hasRole('super_admin') && ! $request->user()->hasRole('super_admin')) {
            abort(403, "Only a super admin can {$verb} a super admin.");
        }
    }

    private function revokeSessions(Admin $admin): void
    {
        $admin->tokens()->delete();
        $admin->forceFill(['refresh_token' => null, 'refresh_token_expires_at' => null])->save(); // match yours
    }

    private function row(Admin $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'email' => $a->email,
            'is_active' => (bool) $a->is_active,
            'roles' => $a->getRoleNames()->values()->all(),
            'permissions' => $a->getAllPermissions()->pluck('name')->values()->all(),
            'direct_permissions' => $a->getDirectPermissions()->pluck('name')->values()->all(),
            'last_login_at' => $a->last_login_at?->toIso8601String(), // match yours (null if you don't track it)
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->toProfileArray()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
        ], ['phone.regex' => 'Enter a valid phone number.']);
 
        $admin = $request->user();
 
        if (array_key_exists('name', $data)) {
            $admin->name = trim($data['name']);
        }
        if (array_key_exists('phone', $data)) {
            $admin->phone = filled($data['phone']) ? trim($data['phone']) : null;
        }
        $admin->save();
 
        return response()->json(['message' => 'Profile updated.', 'data' => $admin->refresh()->toProfileArray()]);
    }
 
    public function photo(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], ['photo.max' => 'The photo must be 2MB or smaller.']);
 
        $admin = $request->user();
        $path = $request->file('photo')->store("admin-photos/{$admin->id}", 'public');
 
        $old = $admin->photo_path;
        $admin->forceFill(['photo_path' => $path])->save();
 
        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }
 
        return response()->json(['message' => 'Photo updated.', 'data' => $admin->refresh()->toProfileArray()]);
    }
 
    public function destroyPhoto(Request $request): JsonResponse
    {
        $admin = $request->user();
 
        if ($admin->photo_path) {
            Storage::disk('public')->delete($admin->photo_path);
            $admin->forceFill(['photo_path' => null])->save();
        }
 
        return response()->json(['message' => 'Photo removed.', 'data' => $admin->refresh()->toProfileArray()]);
    }
 
    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', 'max:100', Password::min(8)->letters()->numbers()],
        ], [
            'password.different' => 'Choose a password you have not used just now.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);
 
        $admin = $request->user();
 
        if (! Hash::check($data['current_password'], $admin->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }
 
        $admin->forceFill(['password' => Hash::make($data['password'])])->save();
 
        // Sign out every other device; this one stays signed in.
        $current = $request->user()->currentAccessToken();
        $admin->tokens()->when($current?->id, fn ($q, $id) => $q->where('id', '!=', $id))->delete();
 
        $this->notifyPasswordChanged($admin, $request);
 
        return response()->json(['message' => 'Password changed. Other devices were signed out.']);
    }
 
    /** A failed email must never undo or block the password change, so it is only logged. */
    private function notifyPasswordChanged(Admin $admin, Request $request): void
    {
        try {
            Mail::to($admin->email)->send(new AdminPasswordChangedMail(
                $admin,
                now()->format('D j M Y, g:i A T'),
                $this->describeDevice((string) $request->userAgent()),
                $request->ip(),
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
 
    private function describeDevice(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/'), str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };
 
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'an unknown device',
        };
 
        return "{$browser} on {$os}";
    }
}
