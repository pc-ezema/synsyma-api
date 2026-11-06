<?php

namespace Modules\Admin\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Admin\Emails\ContractorNotice;
use Modules\Admin\Emails\NewApplicationAlert;
use Modules\Admin\Models\Contractor;

/**
 * Sends the contractor emails. A mail problem must never break the admin action,
 * so failures are only logged.
 */
class ContractorNotifier
{
    public static function send(Contractor $contractor, string $event, ?string $reason = null): void
    {
        if (! filled($contractor->email)) {
            return;
        }

        try {
            Mail::to($contractor->email)->queue(new ContractorNotice($contractor, $event, $reason));
        } catch (\Throwable $e) {
            Log::warning('Contractor email failed', ['contractor' => $contractor->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /** Tell the team a new application arrived. Does nothing if no alert emails are configured. */
    public static function notifyTeam(Contractor $contractor): void
    {
        $to = (array) config('app.alert_emails', []);
        if (! $to) {
            return;
        }

        try {
            Mail::to($to)->queue(new NewApplicationAlert($contractor->load(['categories', 'campaign'])));
        } catch (\Throwable $e) {
            Log::warning('Team alert email failed', ['contractor' => $contractor->id, 'error' => $e->getMessage()]);
        }
    }
}
