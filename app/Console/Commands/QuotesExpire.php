<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\User\Models\Booking;

/** Closes quotes the customer never answered. Run hourly. */
class QuotesExpire extends Command
{
    protected $signature = 'quotes:expire';
    protected $description = 'Cancel sent quotes that passed their expiry date';

    public function handle(): int
    {
        $n = 0;
        Booking::where('status', 'quote_sent')->whereNotNull('quote_expires_at')->where('quote_expires_at', '<', now())
            ->each(function (Booking $b) use (&$n) {
                $b->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
                $b->log('quote_expired', 'system', 'quote_sent', 'cancelled', 'The quote expired without an answer.');
                $n++;
            });
        $this->info("Expired {$n} quote(s).");

        return self::SUCCESS;
    }
}