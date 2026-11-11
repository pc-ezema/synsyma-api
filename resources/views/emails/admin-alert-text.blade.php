{!! $heading !!}

Hi {!! !empty($name) ? $name : 'there' !!},

{!! $intro !!}

Open in admin: {!! $url !!}

You are getting this because you chose email alerts in the {!! config('app.name') !!} admin. Choose which alerts reach you on My profile.

--
Synsyma Home Services
Need help? {!! $supportEmail !!}