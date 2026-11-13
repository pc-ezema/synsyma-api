<?php

namespace Modules\User\Console;

use Illuminate\Console\Command;
use Modules\User\Models\AppNotification;
use Modules\User\Models\Booking;
use Modules\User\Support\Notifier;

/** "Your service is tomorrow" and "starting soon". Once each per booking start time. Run hourly. */
class JobReminders extends Command
{
    protected $signature = 'notifications:job-reminders';
    protected $description = 'Remind customers about upcoming jobs (about 24 hours and about 2 hours before)';

    public function handle(): int
    {
        $n = 0;
        $n += $this->send('job_reminder_24h', now()->addHours(23), now()->addHours(24), 30, 'Your service is tomorrow', true);
        $n += $this->send('job_reminder_2h', now()->addHour(), now()->addHours(2), 4, 'Your service starts soon', false);
        $this->info("Sent {$n} reminder(s).");

        return self::SUCCESS;
    }

    private function send(string $type, $from, $to, int $dedupeHours, string $title, bool $email): int
    {
        $n = 0;
        Booking::with(['user', 'contractor'])->whereIn('status', ['confirmed', 'assigned'])
            ->whereBetween('starts_at', [$from, $to])
            ->each(function (Booking $b) use ($type, $dedupeHours, $title, $email, &$n) {
                if (AppNotification::where('booking_id', $b->id)->where('type', $type)->where('created_at', '>=', now()->subHours($dedupeHours))->exists()) {
                    return;
                }
                $when = $b->starts_at->copy()->setTimezone($b->timezone)->format('D j M, g:ia');
                $who = $b->contractor?->name ? " {$b->contractor->name} will be there." : ' We are still confirming your contractor.';
                Notifier::deliver($b, $type, $title, "Your {$b->service_name} is booked for {$when}.{$who} Please make sure someone can give access.", $email);
                $n++;
            });

        return $n;
    }
}