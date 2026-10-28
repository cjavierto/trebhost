<?php
declare(strict_types=1);

namespace App;

/**
 * Data layer. Stripe is the database:
 *   client  = Stripe Customer   (metadata.app = "renewals", metadata.svc = comma list of price ids)
 *   service = Stripe Product + recurring Price
 *   payment = Stripe Subscription (created when the client completes Checkout)
 */
final class Billing
{
    public const TAG = 'renewals';
    public const LIVE = ['active', 'trialing', 'past_due', 'unpaid', 'paused'];

    public static function currency(): string
    {
        return strtolower(Env::get('CURRENCY', 'nzd'));
    }

    /* ---------- clients ---------- */

    public static function isClient(array $customer): bool
    {
        return empty($customer['deleted']) && ($customer['metadata']['app'] ?? '') === self::TAG;
    }

    /** @return array[] */
    public static function clients(): array
    {
        $out = [];
        foreach (StripeApi::listAll('customers') as $c) {
            if (self::isClient($c)) {
                $out[] = $c;
            }
        }
        usort($out, fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
        return $out;
    }

    public static function client(string $id): ?array
    {
        if (!preg_match('/^cus_[A-Za-z0-9]+$/', $id)) {
            return null;
        }
        try {
            $c = StripeApi::request('GET', 'customers/' . $id);
        } catch (StripeException) {
            return null;
        }
        return self::isClient($c) ? $c : null;
    }

    public static function serviceIds(array $customer): array
    {
        return array_values(array_filter(explode(',', (string)($customer['metadata']['svc'] ?? ''))));
    }

    public static function linkVersion(array $customer): int
    {
        return (int)($customer['metadata']['link_v'] ?? 0);
    }

    public static function payUrl(array $customer): string
    {
        return base_url() . '/c/' . Auth::payToken($customer['id'], self::linkVersion($customer));
    }

    /* ---------- services ---------- */

    public static function serviceFromPrice(array $price): array
    {
        $prod = is_array($price['product'] ?? null) ? $price['product'] : [];
        return [
            'id' => $price['id'],
            'name' => (string)($prod['name'] ?? 'Service'),
            'description' => (string)($prod['description'] ?? ''),
            'amount' => (int)($price['unit_amount'] ?? 0),
            'interval' => (($price['recurring']['interval'] ?? 'month') === 'year') ? 'year' : 'month',
            'anchor' => isset($price['metadata']['anchor']) ? (int)$price['metadata']['anchor'] : null,
            'active' => (bool)($price['active'] ?? false),
            'product' => is_array($price['product'] ?? null) ? ($price['product']['id'] ?? null) : ($price['product'] ?? null),
        ];
    }

    /** Services for one client (parallel lookups). */
    public static function services(array $customer): array
    {
        $reqs = [];
        foreach (self::serviceIds($customer) as $id) {
            $reqs[$id] = ['prices/' . $id, ['expand' => ['product']]];
        }
        $out = [];
        foreach (StripeApi::getMany($reqs) as $price) {
            if ($price && !empty($price['active']) && !empty($price['recurring'])) {
                $out[] = self::serviceFromPrice($price);
            }
        }
        return $out;
    }

    /* ---------- subscriptions ---------- */

    public static function subFromStripe(array $s): array
    {
        $item = $s['items']['data'][0] ?? [];
        $inv = is_array($s['latest_invoice'] ?? null) ? $s['latest_invoice'] : [];
        $cust = is_array($s['customer'] ?? null) ? ($s['customer']['id'] ?? '') : (string)($s['customer'] ?? '');
        return [
            'id' => $s['id'],
            'customer' => $cust,
            'price' => (string)($item['price']['id'] ?? ''),
            'interval' => (string)($item['price']['recurring']['interval'] ?? 'month'),
            'status' => (string)$s['status'],
            'period_end' => isset($s['current_period_end']) ? (int)$s['current_period_end'] : null,
            'cancel_at_period_end' => !empty($s['cancel_at_period_end']),
            'invoice_url' => $inv['hosted_invoice_url'] ?? null,
            'retry_at' => isset($inv['next_payment_attempt']) ? (int)$inv['next_payment_attempt'] : null,
        ];
    }

    public static function subsFor(string $customerId): array
    {
        $raw = StripeApi::listAll('subscriptions', [
            'customer' => $customerId,
            'status' => 'all',
            'expand' => ['data.latest_invoice'],
        ], 200);
        return array_map([self::class, 'subFromStripe'], $raw);
    }

    /** @return array{state:string,sub:?array} state = active | due | failed */
    public static function state(array $service, array $subs): array
    {
        foreach ($subs as $s) {
            if ($s['price'] === $service['id'] && in_array($s['status'], self::LIVE, true)) {
                $failed = in_array($s['status'], ['past_due', 'unpaid'], true);
                return ['state' => $failed ? 'failed' : 'active', 'sub' => $s];
            }
        }
        return ['state' => 'due', 'sub' => null];
    }

    /** Most urgent state across a client's services. */
    public static function rollup(array $states): string
    {
        foreach (['failed', 'due', 'active'] as $want) {
            foreach ($states as $s) {
                if ($s === $want) {
                    return $want;
                }
            }
        }
        return 'none';
    }

    /**
     * Whole-business snapshot (3 paged Stripe calls) for the dashboard and payments page.
     * @return array{clients:array[],rows:array<string,array>,prices:array<string,array>}
     */
    public static function snapshot(): array
    {
        $clients = self::clients();
        $prices = [];
        foreach (StripeApi::listAll('prices', ['expand' => ['data.product']]) as $p) {
            if (($p['metadata']['app'] ?? '') === self::TAG) {
                $prices[$p['id']] = self::serviceFromPrice($p);
            }
        }
        $subsBy = [];
        foreach (StripeApi::listAll('subscriptions', ['status' => 'all', 'expand' => ['data.latest_invoice']]) as $s) {
            $sub = self::subFromStripe($s);
            $subsBy[$sub['customer']][] = $sub;
        }
        $rows = [];
        foreach ($clients as $c) {
            $services = [];
            foreach (self::serviceIds($c) as $id) {
                if (isset($prices[$id]) && $prices[$id]['active']) {
                    $services[] = $prices[$id];
                }
            }
            $subs = $subsBy[$c['id']] ?? [];
            $states = [];
            foreach ($services as $svc) {
                $states[] = self::state($svc, $subs)['state'];
            }
            $rows[$c['id']] = [
                'services' => $services,
                'subs' => $subs,
                'states' => $states,
                'status' => self::rollup($states),
            ];
        }
        return ['clients' => $clients, 'rows' => $rows, 'prices' => $prices];
    }
}
