<?php

namespace Modules\Admin\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** An alert for an admin, in the same design as the customer emails. Sent only to admins who switched email on. */
class AdminAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $label,
        public string $headline,
        public string $text,
        public ?string $path = null,
    ) {}

    public function build(): self
    {
        $app = config('app.name', 'Synsyma');
        $base = rtrim((string) (config('app.admin_url') ?: config('app.url')), '/');

        return $this->subject("{$this->headline} | {$app} admin")
            ->view('admin::emails.admin-alert')
            ->text('admin::emails.admin-alert-text')
            ->with([
                'name' => $this->name,
                'label' => $this->label,
                'heading' => $this->headline,
                'intro' => $this->text,
                'url' => $base . ($this->path ?: '/notifications'),
                // Use the same value your OTP mailable passes as $logoUrl (copy that line if it differs).
                'logoUrl' => config('app.logo_url', rtrim((string) config('app.url'), '/') . '/logo.png'),
                'supportEmail' => config('mail.from.address'),
            ]);
    }
}