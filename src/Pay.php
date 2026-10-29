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
        $dueCount = 0;
        $dueToday = 0;
        foreach ($services as $svc) {
            $st = Billing::state($svc, $subs);
            if ($st['state'] !== 'active') {
                $due += $svc['amount'];
            }
            if ($st['state'] === 'due') {
                $dueCount++;
                if (!($svc['anchor'] && $svc['anchor'] > time() + 3600)) {
                    $dueToday += $svc['amount'];
                }
            }
            $rows[] = ['svc' => $svc] + $st;
        }

        $banner = null;
        $error = take_flash();
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
                            : 'Your card is saved and your services are set up. Anything due today has been charged; the rest will be charged on the dates shown below.';
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
            'dueCount' => $dueCount,
            'dueToday' => $dueToday,
            'error' => $error,
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
        $all = Billing::services($client);
        $subs = Billing::subsFor($client['id']);

        if ($priceId === 'all') {
            // One card setup for every service that still needs payment.
            $due = array_values(array_filter($all, fn($s) => Billing::state($s, $subs)['state'] === 'due'));
            if (!$due) {
                redirect($back);
            }
            if (count($due) === 1) {
                $priceId = $due[0]['id'];
            } else {
                $session = StripeApi::request('POST', 'checkout/sessions', [
                    'mode' => 'setup',
                    'customer' => $client['id'],
                    'payment_method_types' => ['card'],
                    'success_url' => base_url() . $back . '/complete?sid={CHECKOUT_SESSION_ID}',
                    'cancel_url' => base_url() . $back,
                    'metadata' => ['app' => Billing::TAG, 'kind' => 'setup_all'],
                ]);
                redirect($session['url']);
            }
        }

        if (!in_array($priceId, Billing::serviceIds($client), true)) {
            redirect($back);
        }
        $services = array_values(array_filter($all, fn($s) => $s['id'] === $priceId));
        if (!$services) {
            redirect($back);
        }
        $service = $services[0];
        $st = Billing::state($service, $subs);

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

    /** Stripe sends the client back here after the one-time card setup. */
    public static function complete(string $token): never
    {
        $client = self::resolve($token) ?? self::invalid();
        $back = '/c/' . $token;
        $sid = (string)($_GET['sid'] ?? '');
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $sid)) {
            redirect($back);
        }
        try {
            $session = StripeApi::request('GET', 'checkout/sessions/' . $sid, ['expand' => ['setup_intent']]);
            $sessionCustomer = is_array($session['customer'] ?? null) ? ($session['customer']['id'] ?? '') : ($session['customer'] ?? '');
            if ($sessionCustomer !== $client['id'] || ($session['status'] ?? '') !== 'complete') {
                redirect($back);
            }
            $failed = self::activate($client, $session);
        } catch (StripeException $e) {
            error_log('Setup completion error: ' . $e->getMessage());
            flash('error', 'Your card was saved, but we couldn\'t finish setting up every service. Please refresh this page, or contact us if it still shows "Awaiting payment".');
            redirect($back . '?paid=1&sid=' . $sid);
        }
        if ($failed) {
            flash('error', 'Your card was saved, but we couldn\'t set up: ' . implode(', ', $failed) . '. Please use the Pay now button on that service, or contact us.');
        }
        redirect($back . '?paid=1&sid=' . $sid);
    }

    /**
     * Turn a completed card-setup session into one subscription per unpaid service.
     * Safe to call twice (page return and webhook): services that already have a
     * live subscription are skipped, and Stripe idempotency keys cover races.
     * @return string[] names of services that could not be set up
     */
    public static function activate(array $client, array $session): array
    {
        $si = $session['setup_intent'] ?? null;
        $pm = is_array($si) ? ($si['payment_method'] ?? null) : null;
        $pm = is_array($pm) ? ($pm['id'] ?? null) : $pm;
        if (!$pm) {
            return [];
        }
        StripeApi::request('POST', 'customers/' . $client['id'], [
            'invoice_settings' => ['default_payment_method' => $pm],
        ]);
        $subs = Billing::subsFor($client['id']);
        $failed = [];
        foreach (Billing::services($client) as $svc) {
            if (Billing::state($svc, $subs)['state'] !== 'due') {
                continue;
            }
            $params = [
                'customer' => $client['id'],
                'items' => [['price' => $svc['id']]],
                'default_payment_method' => $pm,
                'payment_behavior' => 'error_if_incomplete',
                'metadata' => ['app' => Billing::TAG],
            ];
            if ($svc['anchor'] && $svc['anchor'] > time() + 3600) {
                $params['billing_cycle_anchor'] = $svc['anchor'];
                $params['proration_behavior'] = 'none';
            }
            try {
                StripeApi::request('POST', 'subscriptions', $params, 'th-' . ($session['id'] ?? '') . '-' . $svc['id']);
            } catch (StripeException $e) {
                error_log('Subscription create failed for ' . $svc['id'] . ': ' . $e->getMessage());
                $failed[] = $svc['name'];
            }
        }
        return $failed;
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
