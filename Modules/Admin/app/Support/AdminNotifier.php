<?php

namespace Modules\Admin\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Admin\Emails\AdminAlert;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\AdminNotification;
use Modules\User\Models\Booking;

/**
 * Tells the admin team what needs them: a line in the bell, a browser push, and (if they chose it) an email.
 *
 * Each kind of alert is only shown to admins whose role can open the page it leads to, and each admin picks
 * push and email per kind on My profile. Driven by the booking timeline, so no controller needs changing.
 * Any failure is only logged: an alert problem must never break a payment, a review or a booking.
 */
class AdminNotifier
{
    /** type => label, who may see it, and what it does until the admin changes it. */
    public const TYPES = [
        'booking_to_assign' => ['label' => 'New booking to assign', 'permission' => 'bookings.manage', 'push' => true, 'email' => false],
        'quote_requested' => ['label' => 'Quote requested', 'permission' => 'bookings.manage', 'push' => true, 'email' => false],
        'low_review' => ['label' => 'Low rating', 'permission' => 'reviews.view', 'push' => true, 'email' => false],
        'booking_created' => ['label' => 'New booking', 'permission' => 'bookings.manage', 'push' => true, 'email' => false],
        'booking_cancelled' => ['label' => 'Customer cancelled', 'permission' => 'bookings.manage', 'push' => true, 'email' => false],
        'booking_rescheduled' => ['label' => 'Customer rescheduled', 'permission' => 'bookings.manage', 'push' => true, 'email' => false],
        'extra_hours_added' => ['label' => 'Extra hours added', 'permission' => 'bookings.manage', 'push' => true, 'email' => false],
        'quote_accepted' => ['label' => 'Quote accepted', 'permission' => 'bookings.manage', 'push' => true, 'email' => false],
        'quote_declined' => ['label' => 'Quote declined', 'permission' => 'bookings.manage', 'push' => true, 'email' => false],
        'review_posted' => ['label' => 'New review', 'permission' => 'reviews.view', 'push' => true, 'email' => false],
        'payment_failed' => ['label' => 'Payment problem', 'permission' => 'bookings.manage', 'push' => true, 'email' => false],
        'problem_reported' => ['label' => 'Customer reported a problem', 'permission' => 'bookings.manage', 'push' => true, 'email' => true],
        'customer_completed' => ['label' => 'Customer completed a job', 'permission' => 'bookings.manage', 'push' => true, 'email' => false],
        'deletion_request' => ['label' => 'Account deleted', 'permission' => 'deletions.view', 'push' => true, 'email' => false],
    ];

    /** Called from the customer Notifier::onEvent, which both BookingEvent models already call. */
    public static function onEvent($event): void
    {
        try {
            $b = Booking::with(['user', 'review'])->find($event->booking_id);
            if (! $b) {
                return;
            }
            $svc = $b->service_name;
            $who = $b->customerName();
            $link = '/bookings/' . $b->id;

            switch ($event->type) {
                case 'created':
                    if ($event->to_status === 'quote_requested') {
                        self::notify('quote_requested', 'Quote requested', "{$who} asked for a price on {$svc}. Booking {$b->reference}.", $link, $b->id, 360);
                    } elseif ($event->to_status === 'confirmed' && ! $b->contractor_id) {
                        self::toAssign($b, $who, $svc, $link);
                    } else {
                        $when = $b->starts_at->copy()->setTimezone($b->timezone)->format('D j M, g:ia');
                        self::notify('booking_created', 'New booking', "{$who} booked {$svc} for {$when}. Waiting for payment. Booking {$b->reference}.", $link, $b->id, 5);
                    }
                    break;

                case 'cancelled': // the customer cancelled (the admin's own cancels are logged as "status")
                    $extra = $b->contractor_id ? ' A contractor is assigned, so let them know.' : '';
                    self::notify('booking_cancelled', 'Customer cancelled', "{$who} cancelled {$svc} ({$b->reference}).{$extra}", $link, $b->id);
                    break;

                case 'rescheduled':
                    if ($event->actor === 'customer') {
                        $extra = $b->contractor_id ? ' A contractor is assigned, so let them know.' : '';
                        self::notify('booking_rescheduled', 'Customer rescheduled', "{$who} moved {$svc} ({$b->reference}). " . trim((string) $event->note) . $extra, $link, $b->id);
                    }
                    break;

                case 'extra_hours':
                    self::notify('extra_hours_added', 'Extra hours added', "{$who} added more time to {$svc} ({$b->reference}). " . trim((string) $event->note), $link, $b->id);
                    break;

                case 'quote_accepted':
                    self::notify('quote_accepted', 'Quote accepted', "{$who} accepted the quote for {$svc} ({$b->reference}). They can pay now.", $link, $b->id);
                    break;

                case 'quote_declined':
                    self::notify('quote_declined', 'Quote declined', "{$who} closed the quote for {$svc} ({$b->reference}). " . trim((string) $event->note), $link, $b->id);
                    break;

                case 'payment_succeeded':
                case 'payment_received':
                    if (! $b->contractor_id && ! in_array($b->status, ['cancelled', 'refunded', 'completed', 'in_progress', 'en_route'], true)) {
                        self::toAssign($b, $who, $svc, $link);
                    }
                    break;

                case 'review_added':
                case 'review_edited':
                    $rating = $b->review?->rating;
                    if ($rating !== null && (int) $rating <= 2) {
                        $comment = trim((string) $b->review->comment);
                        $snippet = $comment !== '' ? ' "' . Str::limit($comment, 120) . '"' : '';
                        self::notify('low_review', "{$rating}-star review", "{$who} rated {$svc} {$rating} out of 5.{$snippet}", $link, $b->id, 1440);
                    } elseif ($rating !== null) {
                        $comment = trim((string) $b->review->comment);
                        $snippet = $comment !== '' ? ' "' . Str::limit($comment, 120) . '"' : '';
                        self::notify('review_posted', "{$rating}-star review", "{$who} rated {$svc} {$rating} out of 5.{$snippet}", $link, $b->id, 1440);
                    }
                    break;

                case 'payment_failed':
                    self::notify('payment_failed', 'Payment failed', "A payment for {$svc} ({$b->reference}) from {$who} did not go through.", $link, $b->id, 30);
                    break;
            }
        } catch (\Throwable $e) {
            Log::warning('Admin notification failed', ['event' => $event->id ?? null, 'error' => $e->getMessage()]);
        }
    }

    /** Called when a customer deletes their account (see STEPS.md). */
    public static function deletion($deletion): void
    {
        try {
            $name = trim((string) ($deletion->name ?? '')) ?: ($deletion->email ?? 'A customer');
            self::notify('deletion_request', 'Account deleted', "{$name} deleted their account.", '/deletions');
        } catch (\Throwable $e) {
            Log::warning('Admin notification failed', ['error' => $e->getMessage()]);
        }
    }

    private static function toAssign(Booking $b, string $who, string $svc, string $link): void
    {
        self::notify('booking_to_assign', 'New booking to assign', "{$who} booked {$svc}. It is paid and needs a contractor. Booking {$b->reference}.", $link, $b->id, 360);
    }

    /** Store the alert, then push and email the admins who may see it and want it. */
    public static function notify(string $type, string $title, string $body, ?string $link = null, ?string $bookingId = null, int $dedupeMinutes = 0): void
    {
        $meta = self::TYPES[$type] ?? null;
        if (! $meta) {
            return;
        }
        if ($dedupeMinutes && $bookingId && AdminNotification::where('type', $type)->where('booking_id', $bookingId)->where('created_at', '>=', now()->subMinutes($dedupeMinutes))->exists()) {
            return; // the same thing was already announced a moment ago
        }

        AdminNotification::create(['type' => $type, 'title' => $title, 'body' => $body, 'link' => $link, 'permission' => $meta['permission'], 'booking_id' => $bookingId]);

        $prefs = DB::table('admin_notification_prefs')->where('type', $type)->get()->keyBy('admin_id');
        $pushTo = [];

        foreach (Admin::all() as $admin) {
            try {
                if (! $admin->can($meta['permission'])) {
                    continue;
                }
                $p = $prefs->get($admin->id);
                $wantsPush = $p ? (bool) $p->push : $meta['push'];
                $wantsEmail = $p ? (bool) $p->email : $meta['email'];

                if ($wantsPush) {
                    $pushTo[] = $admin->id;
                }
                if ($wantsEmail && filled($admin->email)) {
                    Mail::to($admin->email)->queue(new AdminAlert((string) $admin->name, $meta['label'], $title, $body, $link));
                }
            } catch (\Throwable $e) {
                Log::warning('Admin alert delivery failed', ['admin' => $admin->id ?? null, 'error' => $e->getMessage()]);
            }
        }

        AdminPush::send($pushTo, $title, $body, $link, $type);
    }

    /** Every type with this admin's push and email choice (defaults filled in), for the types they may see. */
    public static function preferencesFor($admin): array
    {
        $rows = DB::table('admin_notification_prefs')->where('admin_id', $admin->id)->get()->keyBy('type');
        $out = [];
        foreach (self::TYPES as $type => $meta) {
            if (! $admin->can($meta['permission'])) {
                continue;
            }
            $r = $rows->get($type);
            $out[] = ['type' => $type, 'label' => $meta['label'], 'push' => $r ? (bool) $r->push : $meta['push'], 'email' => $r ? (bool) $r->email : $meta['email']];
        }

        return $out;
    }

    /** The permissions this admin holds among those used by the alert types. */
    public static function allowedPermissions($admin): array
    {
        return collect(self::TYPES)->pluck('permission')->unique()->filter(fn ($p) => $admin->can($p))->values()->all();
    }
}