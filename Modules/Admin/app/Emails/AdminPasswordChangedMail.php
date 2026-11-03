<?php

namespace Modules\Admin\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Admin\Models\Admin;

/** Security notice: an admin's password was changed. Never contains the password. */
class AdminPasswordChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Admin $admin,
        public string $changedAt,
        public string $device,
        public ?string $ip = null,
        public bool $signedOutOthers = true,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Synsyma password was changed');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-password-changed',
            text: 'emails.admin-password-changed-text',
            with: [
                'name' => $this->admin->name,
                'changedAt' => $this->changedAt,
                'device' => $this->device,
                'ip' => $this->ip,
                'signedOutOthers' => $this->signedOutOthers,
                'logoUrl' => url('logo.png'),
                'supportEmail' => config('mail.from.address'),
            ],
        );
    }
}