<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\User\Models\Booking;
use Modules\Admin\Models\PayoutEntry;
use Modules\Admin\Support\Ledger;

/**
 * Creates the earning for completed jobs that have none (jobs finished before payouts existed, or if a hook failed).
 * Safe to run any time: a booking only ever gets one earning.
 *   php artisan payouts:backfill
 */
class PayoutsBackfill extends Command
{
    protected $signature = 'payouts:backfill';

    protected $description = 'Create missing contractor earnings for completed bookings';

    public function handle(): int
    {
        $done = PayoutEntry::where('type', 'earning')->pluck('booking_id');
        $made = 0;

        Booking::where('status', 'completed')->whereNotNull('contractor_id')->whereNotIn('id', $done)->orderBy('completed_at')
            ->each(function (Booking $b) use (&$made) {
                Ledger::onCompleted($b);
                $made++;
            });

        $this->info("Checked {$made} completed " . ($made === 1 ? 'booking' : 'bookings') . '.');

        return self::SUCCESS;
    }
}