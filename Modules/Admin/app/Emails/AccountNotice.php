<?php

namespace Modules\Admin\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** A short account email in the same design as the OTP email. With a $url it shows one button. */
class AccountNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $label,
        public string $headline,
        public string $text,
        public ?string $url = null,
        public string $buttonLabel = 'Open',
    ) {}

    public function build(): self
    {
        $app = config('app.name', 'Synsyma');

        return $this->subject("{$this->headline} | {$app}")
            ->view('emails.account-notice')
            ->text('emails.account-notice-text')
            ->with([
                'name' => $this->name,
                'label' => $this->label,
                'heading' => $this->headline,
                'intro' => $this->text,
                'url' => $this->url,
                'buttonLabel' => $this->buttonLabel,
                // Use the same value your OTP mailable passes as $logoUrl (copy that line if it differs).
                'logoUrl' => config('app.logo_url', rtrim((string) config('app.url'), '/') . '/logo.png'),
                'supportEmail' => config('mail.from.address'),
            ]);
    }
}