{!! $heading !!}

Hi {!! !empty($name) ? $name : 'there' !!},

{!! $intro !!}

Booking reference: {!! $reference !!}

Open the {!! config('app.name') !!} app and go to Bookings to see the details.

Something not right? Message us from the booking in the app and our team will help.

You are getting this because you have a booking with {!! config('app.name') !!}. This is an automatic update, so there is no need to reply.

--
Synsyma Home Services
Need help? {!! $supportEmail !!}