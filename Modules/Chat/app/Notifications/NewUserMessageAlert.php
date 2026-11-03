<?php

namespace Modules\Chat\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NewUserMessageAlert extends Notification
{
    public function __construct(
        private ?string $adminName,
        private string $customerName,
        private string $preview,
        private string $link,
        private int $unread = 1,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $first = $this->adminName ? Str::before(trim($this->adminName), ' ') : null;

        return (new MailMessage)
            ->subject("New message from {$this->customerName}")
            ->view(['emails.chat-alert', 'emails.chat-alert-text'], [
                'greeting' => $first ? "Hi {$first}," : 'Hi there,',
                'customerName' => $this->customerName,
                'initials' => $this->initials(),
                'preview' => $this->preview,
                'unreadLabel' => $this->unread > 1 ? "{$this->unread} unread" : 'New',
                'sentAt' => now()->format('D j M, g:i A'),
                'link' => $this->link,
                'logoUrl' => url('logo.png'),
                'supportEmail' => config('mail.from.address'),
            ]);
    }

    private function initials(): string
    {
        $letters = collect(preg_split('/\s+/', trim($this->customerName)))
            ->filter()
            ->take(2)
            ->map(fn ($word) => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');

        return $letters !== '' ? $letters : '?';
    }
}