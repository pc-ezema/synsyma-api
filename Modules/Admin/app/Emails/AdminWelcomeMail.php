<?php

namespace Modules\Admin\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Admin\Models\Admin;

/**
 * Sent when an admin is created, and again when login details are resent ($isReset = true).
 *
 * Deliberately NOT queued: it carries a plain-text password, and a queued mail would store that
 * password in the jobs table until it runs.
 */
class AdminWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $roleLabels
     */
    public function __construct(
        public Admin $admin,
        public string $password,
        public array $roleLabels = [],
        public ?string $createdBy = null,
        public bool $isReset = false,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isReset ? 'Your new Synsyma login details' : 'Your Synsyma admin account is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-welcome',
            text: 'emails.admin-welcome-text',
            with: [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'password' => $this->password,
                'roles' => $this->roleLabels,
                'createdBy' => $this->createdBy,
                'isReset' => $this->isReset,
                'loginUrl' => rtrim(config('app.admin_url', config('app.url')), '/').'/login',
                'logoUrl' => url('logo.png'),
                'supportEmail' => config('mail.from.address'),
            ],
        );
    }
}