<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Stripe\StripeClient;

class StripeWebhookCreate extends Command
{
    protected $signature = 'stripe:webhook {url : Public https URL, e.g. https://api.synsyma.com/api/v1/webhooks/stripe}';
    protected $description = 'Create the Stripe webhook endpoint and print its signing secret';

    public function handle(): int
    {
        $key = config('stripe.secret');
        if (! $key) {
            $this->error('Set STRIPE_SECRET in .env first.');
            return self::FAILURE;
        }

        $url = $this->argument('url');
        if (! str_starts_with($url, 'https://')) {
            $this->error('Stripe needs a public https URL (not localhost).');
            return self::FAILURE;
        }

        $stripe = new StripeClient($key);

        foreach ($stripe->webhookEndpoints->all(['limit' => 100])->data as $e) {
            if ($e->url === $url) {
                $this->warn("An endpoint for this URL already exists ({$e->id}). Its secret can only be seen in the dashboard. Delete it there to recreate it.");
                return self::FAILURE;
            }
        }

        $e = $stripe->webhookEndpoints->create([
            'url' => $url,
            'description' => 'Synsyma bookings',
            'enabled_events' => [
                'checkout.session.completed',
                'payment_intent.succeeded',
                'payment_intent.payment_failed',
                'charge.refunded',
            ],
        ]);

        $this->info("Created {$e->id}");
        $this->line('Add this to .env (shown only once):');
        $this->line("STRIPE_WEBHOOK_SECRET={$e->secret}");

        return self::SUCCESS;
    }
}