<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\User\Models\Booking;
use Modules\User\Support\BookingCompletion;

class AutoCompleteBookings extends Command
{
    protected $signature = 'bookings:auto-complete';
    protected $description = 'Complete in-progress jobs whose time ended long enough ago, unless the customer reported a problem.';

    public function handle(): int
    {
        $n = 0;
        Booking::where('status', 'in_progress')->whereNull('problem_reported_at')->where('starts_at', '<', now()->subHour())
            ->chunkById(100, function ($rows) use (&$n) {
                foreach ($rows as $b) {
                    $at = BookingCompletion::autoCompleteAt($b);
                    if ($at && $at->isPast() && BookingCompletion::complete($b->id, 'system', 'Completed automatically after the job time ended.')) {
                        $n++;
                    }
                }
            });
        $this->info("Auto-completed {$n} booking(s).");

        return self::SUCCESS;
    }
}