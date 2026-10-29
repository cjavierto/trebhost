<?php
declare(strict_types=1);

namespace App;

/**
 * Stripe webhook receiver (POST /webhook/stripe).
 * Stripe events to enable: invoice.payment_failed, invoice.paid, checkout.session.completed
 */
final class Webhook
{
    public static function handle(): void
    {
        $secret = Env::get('STRIPE_WEBHOOK_SECRET');
        if ($secret === '') {
            http_response_code(500);
            echo 'STRIPE_WEBHOOK_SECRET is not set';
            return;
        }
        $payload = (string)file_get_contents('php://input');
        try {
            $event = StripeApi::verifyWebhook($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', $secret);
        } catch (StripeException $e) {
            http_response_code(400);
            echo 'Invalid signature';
            return;
        }

        // Record the delivery (powers the Webhook status in Settings) and skip repeats.
        $state = Store::get('webhook', []);
        $seen = $state['recent'] ?? [];
        if (in_array($event['id'], $seen, true)) {
            echo 'ok (duplicate)';
            return;
        }
        $seen[] = $event['id'];
        $state = ['last_at' => time(), 'last_type' => $event['type'], 'recent' => array_slice($seen, -50)];
        Store::put('webhook', $state);

        try {
            match ($event['type']) {
                'invoice.payment_failed' => self::paymentFailed($event['data']['object']),
                'invoice.paid' => self::paymentSucceeded($event['data']['object']),
                'checkout.session.completed' => self::setupCompleted($event['data']['object']),
                default => null,
            };
        } catch (\Throwable $e) {
            error_log('Webhook handler error: ' . $e->getMessage());
        }
        echo 'ok';
    }

    /** Card setup finished but the client never came back to the page: finish it here. */
    private static function setupCompleted(array $session): void
    {
        if (($session['mode'] ?? '') !== 'setup' || ($session['metadata']['kind'] ?? '') !== 'setup_all') {
            return;
        }
        $client = self::clientFor($session);
        if (!$client) {
            return;
        }
        $full = StripeApi::request('GET', 'checkout/sessions/' . $session['id'], ['expand' => ['setup_intent']]);
        Pay::activate($client, $full);
    }

    private static function clientFor(array $invoice): ?array
    {
        $id = is_array($invoice['customer'] ?? null) ? ($invoice['customer']['id'] ?? '') : (string)($invoice['customer'] ?? '');
        return $id !== '' ? Billing::client($id) : null;
    }

    private static function describe(array $invoice): string
    {
        return (string)($invoice['lines']['data'][0]['description'] ?? 'your service');
    }

    private static function paymentFailed(array $invoice): void
    {
        $client = self::clientFor($invoice);
        if (!$client) {
            return;
        }
        $s = settings();
        $amount = money((int)($invoice['amount_due'] ?? 0));
        $name = (string)($client['name'] ?? 'A client');

        if ($s['notify_fail_admin']) {
            Mailer::send($s['billing_email'], 'Payment failed: ' . $name,
                "A payment of $amount from $name failed.\n\n" .
                'Service: ' . self::describe($invoice) . "\n" .
                'Reason: ' . ($invoice['last_finalization_error']['message'] ?? 'card declined or unavailable') . "\n\n" .
                'Client page: ' . base_url() . '/admin/clients/' . $client['id'] . "\n");
        }
        if ($s['notify_fail_client'] && !empty($client['email'])) {
            Mailer::send($client['email'], 'Your payment to ' . $s['business_name'] . ' did not go through',
                "Hi $name,\n\n" .
                "We couldn't collect your payment of $amount for " . self::describe($invoice) . ".\n\n" .
                "You can pay now or update your card here:\n" . Billing::payUrl($client) . "\n\n" .
                'Questions? Reply to this email or contact ' . $s['billing_email'] . ".\n\n" .
                $s['business_name'] . "\n");
        }
    }

    private static function paymentSucceeded(array $invoice): void
    {
        $s = settings();
        if (!$s['notify_success_admin'] || (int)($invoice['amount_paid'] ?? 0) <= 0) {
            return;
        }
        $client = self::clientFor($invoice);
        if (!$client) {
            return;
        }
        Mailer::send($s['billing_email'], 'Payment received: ' . ($client['name'] ?? 'client'),
            money((int)$invoice['amount_paid']) . ' received from ' . ($client['name'] ?? 'a client') . ' for ' . self::describe($invoice) . ".\n");
    }
}
