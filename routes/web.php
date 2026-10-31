<?php

use Illuminate\Support\Facades\Route;

if (app()->environment('local')) {
    Route::get('/mail-preview', fn () => view('emails.otp', [
        'code' => '482916', 'minutes' => 10, 'name' => 'Ada',
        'label' => 'Email verification', 'heading' => 'Verify your email',
        'intro' => 'Welcome to Synsyma. Enter this code in the app to confirm your email address and finish setting up your account.',
        'logoUrl' => url('logo.png'),
        'supportEmail' => config('mail.from.address'),
    ]));
}
