<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\User\Support\PayPalGateway;

class PayPalWebhookCreate extends Command
{
    protected $signature = 'paypal:webhook {url : Public https URL, e.g. https://api.synsyma.com/api/v1/webhooks/paypal}';
    protected $description = 'Create the PayPal webhook and print its id';

    public function handle(PayPalGateway $paypal): int
    {
        if (! $paypal->enabled()) {
            $this->error('Set PAYPAL_CLIENT_ID and PAYPAL_SECRET in .env first.');

            return self::FAILURE;
        }
        $url = $this->argument('url');
        if (! str_starts_with($url, 'https://')) {
            $this->error('PayPal needs a public https URL (not localhost).');

            return self::FAILURE;
        }

        try {
            $hook = $paypal->api('POST', '/v1/notifications/webhooks', [
                'url' => $url,
                'event_types' => [['name' => 'CHECKOUT.ORDER.APPROVED'], ['name' => 'PAYMENT.CAPTURE.COMPLETED'], ['name' => 'PAYMENT.CAPTURE.DENIED']],
            ]);
            $id = $hook->id;
        } catch (\Throwable $e) {
            // Already there? Find it.
            $id = null;
            foreach ($paypal->api('GET', '/v1/notifications/webhooks')->webhooks ?? [] as $w) {
                if ($w->url === $url) {
                    $id = $w->id;
                }
            }
            if (! $id) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->warn('This URL already had a webhook.');
        }

        $this->info('Add this to .env:');
        $this->line("PAYPAL_WEBHOOK_ID={$id}");

        return self::SUCCESS;
    }
}