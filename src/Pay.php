<?php
declare(strict_types=1);

namespace App;

/** The client-facing payment page (/c/<token>). */
final class Pay
{
    /** Valid, un-revoked link -> the Stripe customer, otherwise null. */
    private static function resolve(string $token): ?array
    {
        $parsed = Auth::parsePayToken($token);
        if (!$parsed) {
            return null;
        }
        $client = Billing::client($parsed['id']);
        if (!$client || Billing::linkVersion($client) !== $parsed['version']) {
            return null;
        }
        return $client;
    }

    private static function invalid(): never
    {
        http_response_code(404);
        render('link_invalid', ['title' => 'Link no longer valid'], 'public');
        exit;
    }

    public static function page(string $token): void
    {
        $client = self::resolve($token) ?? self::invalid();
        $services = Billing::services($client);
        $subs = Billing::subsFor($client['id']);

        $rows = [];
        $due = 0;
        foreach ($services as $svc) {
            $st = Billing::state($svc, $subs);
            if ($st['state'] !== 'active') {
                $due += $svc['amount'];
            }
            $rows[] = ['svc' => $svc] + $st;
        }

        $banner = null;
        if (!empty($_GET['paid'])) {
            $banner = ['text' => 'Your payment details were received. Your services below will update within a few seconds, and Stripe will email your receipt.'];
            $sid = (string)($_GET['sid'] ?? '');
            if (preg_match('/^cs_[A-Za-z0-9_]+$/', $sid)) {
                try {
                    $session = StripeApi::request('GET', 'checkout/sessions/' . $sid);
                    $sessionCustomer = is_array($session['customer'] ?? null) ? ($session['customer']['id'] ?? '') : ($session['customer'] ?? '');
                    if ($sessionCustomer === $client['id'] && in_array($session['payment_status'] ?? '', ['paid', 'no_payment_required'], true)) {
                        $amount = (int)($session['amount_total'] ?? 0);
                        $to = !empty($client['email']) ? ' A receipt is on its way to ' . $client['email'] . '.' : '';
                        $banner['text'] = $amount > 0
                            ? 'We\'ve received ' . money($amount) . ' ' . cur() . '.' . $to
                            : 'Your card is saved and your service is set up. You\'ll be charged on the date shown below.';
                    }
                } catch (StripeException) {
                    // keep the generic message
                }
            }
        }

        render('pay', [
            'client' => $client,
            'token' => $token,
            'rows' => $rows,
            'due' => $due,
            'hasPortal' => count($subs) > 0,
            'banner' => $banner,
            'title' => 'Pay your renewals',
        ], 'public');
    }

    public static function checkout(string $token): void
    {
        $client = self::resolve($token) ?? self::invalid();
        $back = '/c/' . $token;
        $priceId = (string)($_POST['price_id'] ?? '');
        if (!in_array($priceId, Billing::serviceIds($client), true)) {
            redirect($back);
        }
        $services = array_values(array_filter(Billing::services($client), fn($s) => $s['id'] === $priceId));
        if (!$services) {
            redirect($back);
        }
        $service = $services[0];
        $st = Billing::state($service, Billing::subsFor($client['id']));

        if ($st['state'] === 'active') {
            redirect($back);
        }
        if ($st['state'] === 'failed') {
            // Pay the open invoice, or fall back to the card portal.
            if (!empty($st['sub']['invoice_url'])) {
                redirect($st['sub']['invoice_url']);
            }
            self::portal($token);
        }

        $params = [
            'mode' => 'subscription',
            'customer' => $client['id'],
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => base_url() . $back . '?paid=1&sid={CHECKOUT_SESSION_ID}',
            'cancel_url' => base_url() . $back,
        ];
        if ($service['anchor'] && $service['anchor'] > time() + 3600) {
            $params['subscription_data'] = [
                'billing_cycle_anchor' => $service['anchor'],
                'proration_behavior' => 'none',
            ];
        }
        $session = StripeApi::request('POST', 'checkout/sessions', $params);
        redirect($session['url']);
    }

    public static function portal(string $token): never
    {
        $client = self::resolve($token) ?? self::invalid();
        $session = StripeApi::request('POST', 'billing_portal/sessions', [
            'customer' => $client['id'],
            'return_url' => base_url() . '/c/' . $token,
        ]);
        redirect($session['url']);
    }
}
