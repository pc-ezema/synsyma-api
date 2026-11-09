<?php

namespace App\Console\Commands;


use Illuminate\Console\Command;
use Modules\User\Models\AppNotification;
use Modules\User\Models\Booking;
use Modules\User\Support\Notifier;

/** Nudges customers whose quote runs out within 24 hours. Once per quote. Run hourly. */
class QuoteReminders extends Command
{
    protected $signature = 'notifications:quote-reminders';
    protected $description = 'Remind customers about quotes that expire within 24 hours';

    public function handle(): int
    {
        $n = 0;
        Booking::with('user')->where('status', 'quote_sent')
            ->whereBetween('quote_expires_at', [now(), now()->addDay()])
            ->each(function (Booking $b) use (&$n) {
                if (AppNotification::where('booking_id', $b->id)->where('type', 'quote_expiring')->where('created_at', '>=', $b->quote_sent_at)->exists()) {
                    return;
                }
                $until = $b->quote_expires_at->copy()->setTimezone($b->timezone)->format('D j M, g:ia');
                Notifier::deliver($b, 'quote_expiring', 'Your quote expires soon', "Your quote for {$b->service_name} ({$b->money($b->total)}) is open until {$until}. Accept it in the app to book.");
                $n++;
            });
        $this->info("Sent {$n} reminder(s).");

        return self::SUCCESS;
    }
}