Your Synsyma password was changed

Hi {!! $name !!},

The password for your Synsyma admin account was just changed.

When: {!! $changedAt !!}
Device: {!! $device !!}
@if (!empty($ip))
IP address: {!! $ip !!}
@endif

If this was you, there is nothing to do.@if (!empty($signedOutOthers)) Your other devices were signed out, so you may need to sign in again there.@endif


Didn't do this? Someone else may have your password. Contact a super admin right away so they can reset it and check your account.
Report it: {!! $supportEmail !!}

--
Synsyma Home Services
Need help? {!! $supportEmail !!}