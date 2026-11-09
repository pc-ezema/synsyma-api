<?php

namespace Modules\User\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\User\Emails\BookingNotice;
use Modules\User\Models\AppNotification;
use Modules\User\Models\Booking;

/**
 * Tells the customer what happened to their booking: a line in the app, a push to their phone and an email.
 * It is driven by the booking timeline, so every status change is covered without touching each controller.
 * Any failure is only logged. A notification problem must never break a payment, an assignment or a cancel.
 */
class Notifier
{
    /** Called from BookingEvent::created (both the Admin and the User model). */
    public static function onEvent($event): void
    {
        try {
            $b = Booking::with('user')->find($event->booking_id);
            if (! $b || ! $b->user_id) {
                return;
            }
            $m = self::describe($b, $event);
            if ($m) {
                self::deliver($b, $m['type'], $m['title'], $m['body'], $m['email'] ?? true);
            }
        } catch (\Throwable $e) {
            Log::warning('Notification failed', ['event' => $event->id ?? null, 'error' => $e->getMessage()]);
        }
    }

    /** Store the line, push it, email it. */
    public static function deliver(Booking $b, string $type, string $title, string $body, bool $email = true): void
    {
        $n = AppNotification::create(['user_id' => $b->user_id, 'type' => $type, 'title' => $title, 'body' => $body, 'booking_id' => $b->id]);

        PushChannel::send((string) $b->user_id, $title, $body, ['type' => 'booking', 'booking_id' => (string) $b->id, 'event' => $type, 'notification_id' => (string) $n->id]);

        if ($email && filled($b->user?->email)) {
            try {
                Mail::to($b->user->email)->queue(new BookingNotice($b->customerName(), $title, $body, $b->reference, $type));
            } catch (\Throwable $e) {
                Log::warning('Booking email failed', ['booking' => $b->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /** What each timeline entry means to the customer. Null means they do not need to hear about it. */
    private static function describe(Booking $b, $e): ?array
    {
        $svc = $b->service_name;
        $when = self::when($b);
        $byCustomer = $e->actor === 'customer';

        switch ($e->type) {
            case 'created':
                if ($e->to_status === 'confirmed') {
                    return ['type' => 'booking_confirmed', 'title' => 'Booking confirmed', 'body' => "Your {$svc} booking for {$when} is confirmed. Reference {$b->reference}."];
                }
                if ($e->to_status === 'quote_requested') {
                    return ['type' => 'quote_requested', 'title' => 'Quote request received', 'body' => "We received your request for {$svc}. Our team will reply with a price soon."];
                }

                return null;

            case 'payment_succeeded':
                return ['type' => 'booking_confirmed', 'title' => 'Payment received', 'body' => "Thank you. Your {$svc} booking for {$when} is confirmed. Reference {$b->reference}."];

            case 'assigned':
                $who = trim((string) $e->note) ?: 'A contractor';

                return ['type' => 'contractor_assigned', 'title' => 'Contractor assigned', 'body' => "{$who} will do your {$svc} on {$when}."];

            case 'rescheduled':
                if ($byCustomer) {
                    return null;
                }

                return ['type' => 'booking_rescheduled', 'title' => 'Booking rescheduled', 'body' => "Your {$svc} booking was moved to {$when}."];

            case 'refund':
                return ['type' => 'refund_issued', 'title' => 'Refund issued', 'body' => "A refund for booking {$b->reference} has been sent to your original payment method. It usually arrives in 5 to 10 business days."];

            case 'quote_sent':
            case 'quote_revised':
                $price = $b->money($b->total);
                $until = $b->quote_expires_at ? ' It is open until ' . $b->quote_expires_at->copy()->setTimezone($b->timezone)->format('D j M') . '.' : '';

                return ['type' => 'quote_ready', 'title' => $e->type === 'quote_revised' ? 'Your quote was updated' : 'Your quote is ready', 'body' => "Your quote for {$svc} is {$price}.{$until} Open the app to accept it."];

            case 'quote_expired':
                return ['type' => 'quote_expired', 'title' => 'Your quote expired', 'body' => "The quote for {$svc} was not accepted in time. Message us and we will send a new one.", 'email' => false];

            case 'status':
                return match ($e->to_status) {
                    'in_progress' => ['type' => 'job_started', 'title' => 'Your job has started', 'body' => "The team has started your {$svc}.", 'email' => false],
                    'completed' => ['type' => 'job_completed', 'title' => 'Job completed', 'body' => "Your {$svc} is finished. Thank you for choosing Synsyma. If anything is not right, message us from the booking."],
                    'cancelled' => $byCustomer ? null : [
                        'type' => 'booking_cancelled',
                        'title' => 'Booking cancelled',
                        'body' => "Your {$svc} booking {$b->reference} was cancelled." . (preg_match('/Refunded\s+(\S+?)\.?(\s|$)/', (string) $e->note, $m) ? " A refund of {$m[1]} is on its way to you." : ''),
                    ],
                    default => null,
                };
        }

        return null;
    }

    private static function when(Booking $b): string
    {
        return $b->starts_at->copy()->setTimezone($b->timezone)->format('D j M, g:ia');
    }
}