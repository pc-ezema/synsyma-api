<?php

namespace Modules\Admin\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Admin\Models\Contractor;

/** Internal alert to the team: a new contractor application is waiting for review. */
class NewApplicationAlert extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public Contractor $contractor) {}

    /**
     * Build the message.
     */
    public function build(): self
    {
        $c = $this->contractor;
        $base = rtrim((string) config('contractors.admin_url'), '/');

        return $this->subject('New contractor application: ' . $c->name)->view('emails.new-application', [
            'c' => $c,
            'categories' => $c->categories->pluck('name')->implode(', '),
            'campaign' => $c->campaign?->name,
            'link' => $base !== '' ? $base . '/contractors/' . $c->id : null,
            'app' => config('app.name', 'Synsyma'),
            'logoUrl' => url('logo.png'),
            'supportEmail' => config('mail.from.address'),
        ]);
    }
}