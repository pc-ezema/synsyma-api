<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\User\Models\AppNotification;
use Modules\User\Models\Booking;
use Modules\User\Support\Notifier;

/** One gentle nudge, two days after a job is done, for customers who have not rated it. Run hourly. */
class ReviewReminders extends Command
{
    protected $signature = 'notifications:review-reminders';
    protected $description = 'Ask customers to rate jobs completed two or more days ago';

    public function handle(): int
    {
        $n = 0;
        Booking::with(['user', 'contractor'])
            ->where('status', 'completed')->whereNotNull('contractor_id')
            ->whereBetween('completed_at', [now()->subDays(10), now()->subDays(2)])
            ->whereDoesntHave('review')
            ->each(function (Booking $b) use (&$n) {
                if (AppNotification::where('booking_id', $b->id)->where('type', 'review_reminder')->exists()) {
                    return;
                }
                $who = $b->contractor?->name ? ' with ' . $b->contractor->name : '';
                Notifier::deliver($b, 'review_reminder', 'How did we do?', "Rate your {$b->service_name}{$who}. It takes a few seconds and helps other customers.", false);
                $n++;
            });
        $this->info("Sent {$n} reminder(s).");

        return self::SUCCESS;
    }
}