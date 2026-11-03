{!! $isReset ? 'Your new Synsyma login details' : 'Your Synsyma admin account is ready' !!}

Hi {!! $name !!},

{!! !empty($createdBy) ? $createdBy : 'A super admin' !!} {!! $isReset ? 'has sent you new login details. Your previous password no longer works.' : 'has set you up on the Synsyma dashboard.' !!} Use the details below to sign in.

Email: {!! $email !!}
Temporary password: {!! $password !!}
@if (!empty($roles))
Role: {!! implode(', ', $roles) !!}
@endif

Sign in:
{!! $loginUrl !!}

Keep these details private. Synsyma will never ask you for your password. If anyone else has seen it, ask a super admin to reset it.

Weren't expecting this? You can ignore this email and let a super admin know.

--
Synsyma Home Services
Need help? {!! $supportEmail !!}