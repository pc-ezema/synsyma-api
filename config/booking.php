<?php

return [
    'min_notice_hours' => 24,   // earliest start = now + this
    'max_days_ahead' => 90,
    'day_start_hour' => 8,      // first start time, local to the customer's address
    'day_end_hour' => 18,       // last start time (18:00)
    'step_minutes' => 30,
];