<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Admin\Models\AdminDeviceToken;
use Modules\Authentication\Models\Otp;
use Modules\Chat\Models\DeviceToken;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune', ['--model' => [Otp::class, DeviceToken::class, AdminDeviceToken::class]])->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('quotes:expire')->hourly();
Schedule::command('notifications:quote-reminders')->hourly();
Schedule::command('bookings:auto-complete')->hourly();