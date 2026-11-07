<?php

namespace Modules\User\Support;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** The rules for when a job can start. Used by booking and by reschedule. */
class Scheduling
{
    /** Local to the customer: a start time on the step, inside opening hours, with enough notice. */
    public static function checkStart(string $iso, string $tz): Carbon
    {
        $cfg = config('booking');
        $start = Carbon::parse($iso)->setTimezone($tz);

        if ($start->second !== 0 || $start->minute % $cfg['step_minutes'] !== 0) {
            throw ValidationException::withMessages(['starts_at' => 'Choose a start time on the hour or half hour.']);
        }
        $minutes = $start->hour * 60 + $start->minute;
        if ($minutes < $cfg['day_start_hour'] * 60 || $minutes > $cfg['day_end_hour'] * 60) {
            throw ValidationException::withMessages(['starts_at' => sprintf('Start times run from %d:00 to %d:00.', $cfg['day_start_hour'], $cfg['day_end_hour'])]);
        }
        if ($start->lt(now()->addHours($cfg['min_notice_hours']))) {
            throw ValidationException::withMessages(['starts_at' => "Please choose a time at least {$cfg['min_notice_hours']} hours ahead."]);
        }
        if ($start->gt(now()->addDays($cfg['max_days_ahead']))) {
            throw ValidationException::withMessages(['starts_at' => "You can book up to {$cfg['max_days_ahead']} days ahead."]);
        }

        return $start;
    }
}