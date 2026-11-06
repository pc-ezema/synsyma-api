<?php

namespace Modules\Profile\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Security notice: a customer's password was changed or reset. Never contains the password. */
class UserPasswordChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public string $name,
        public string $changedAt,
        public string $device,
        public ?string $ip = null,
        public bool $viaReset = false,
        public bool $signedOutOthers = false,
    ) {}

    /**
     * Build the message.
     */
    public function build(): self
    {
        return $this->subject('Your Synsyma password was ' . ($this->viaReset ? 'reset' : 'changed'))
            ->view('emails.user-password-changed', [
                'name' => $this->name,
                'changedAt' => $this->changedAt,
                'device' => $this->device,
                'ip' => $this->ip,
                'viaReset' => $this->viaReset,
                'signedOutOthers' => $this->signedOutOthers,
                'logoUrl' => url('logo.png'),
                'supportEmail' => config('mail.from.address'),
            ]);
    }
}