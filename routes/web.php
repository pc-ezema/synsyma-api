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

    Route::get('/mail-preview/chat-alert', fn () => view('emails.chat-alert', [
        'greeting' => 'Hi Tunde,',
        'customerName' => 'Adaeze Okonkwo',
        'initials' => 'AO',
        'preview' => "Hello, I booked a deep cleaning for Saturday but the cleaner hasn't confirmed yet.",
        'unreadLabel' => '3 unread',
        'sentAt' => now()->format('D j M, g:i A'),
        'link' => 'https://admin.synsyma.com/chat/abc',
        'logoUrl' => url('logo.png'),
        'supportEmail' => config('mail.from.address'),
    ]));
}