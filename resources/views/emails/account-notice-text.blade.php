{!! $heading !!}

Hi {!! !empty($name) ? $name : 'there' !!},

{!! $intro !!}
@if (!empty($url))

{!! $buttonLabel !!}: {!! $url !!}
@endif

Not you? Contact us right away at {!! $supportEmail !!} and we will secure your account.

--
Synsyma Home Services