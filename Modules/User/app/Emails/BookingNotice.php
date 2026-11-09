<?php

namespace Modules\User\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** One email for every booking update, in the same design as the OTP email. The wording comes from the Notifier. */
class BookingNotice extends Mailable
{
    use Queueable, SerializesModels;

    /** The small pill above the heading. */
    private const LABELS = [
        'booking_confirmed' => 'Booking confirmed',
        'contractor_assigned' => 'Contractor assigned',
        'booking_rescheduled' => 'Booking update',
        'job_started' => 'Job update',
        'job_completed' => 'Job completed',
        'booking_cancelled' => 'Booking cancelled',
        'refund_issued' => 'Refund',
        'quote_requested' => 'Quote request',
        'quote_ready' => 'Your quote',
        'quote_expiring' => 'Your quote',
        'quote_expired' => 'Your quote',
    ];

    public function __construct(
        public string $name,
        public string $headline,
        public string $text,
        public string $reference,
        public string $type = '',
    ) {}

    public function build(): self
    {
        $app = config('app.name', 'Synsyma');

        return $this->subject("{$this->headline} | {$app}")
            ->view('user::emails.booking-notice')
            ->text('user::emails.booking-notice-text')
            ->with([
                'name' => $this->name,
                'heading' => $this->headline,
                'intro' => $this->text,
                'reference' => $this->reference,
                'label' => self::LABELS[$this->type] ?? 'Booking update',
                // Use the same value your OTP mailable passes as $logoUrl (copy that line if it differs).
                'logoUrl' => config('app.logo_url', rtrim((string) config('app.url'), '/') . '/logo.png'),
                'supportEmail' => config('mail.from.address'),
            ]);
    }
}