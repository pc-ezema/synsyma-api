<?php

namespace Modules\Admin\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Admin\Models\Contractor;

/** One mail for every contractor event: applied, added, approved, rejected, suspended, reinstated. */
class ContractorNotice extends Mailable
{
    use Queueable, SerializesModels;

    public const EVENTS = ['applied', 'added', 'approved', 'rejected', 'suspended', 'reinstated'];

    /**
     * Create a new message instance.
     */
    public function __construct(public Contractor $contractor, public string $event, public ?string $reason = null) {}

    /**
     * Build the message.
     */
    public function build(): self
    {
        $app = config('app.name', 'Synsyma');

        $subject = match ($this->event) {
            'applied' => "We received your application | {$app}",
            'added' => "Welcome to {$app}",
            'approved' => "You're approved on {$app}",
            'rejected' => "Update on your {$app} application",
            'suspended' => "Your {$app} contractor account is suspended",
            'reinstated' => "Your {$app} contractor account is active again",
            default => $app,
        };

        return $this->subject($subject)->view('emails.contractor-notice', [
            'name' => $this->contractor->contact_name ?: $this->contractor->name,
            'event' => $this->event,
            'reason' => $this->reason,
            'app' => $app,
            'logoUrl' => url('logo.png'),
            'supportEmail' => config('mail.from.address'),
        ]);
    }
}