<?php
declare(strict_types=1);

namespace App;

class StripeException extends \RuntimeException
{
    public int $httpStatus = 0;
}

/**
 * Minimal Stripe REST client using PHP's cURL extension (no Composer needed).
 * API version is pinned so Stripe account upgrades can never change behaviour.
 */
final class StripeApi
{
    public const VERSION = '2024-06-20';

    private static function base(): string
    {
        return rtrim(Env::get('STRIPE_API_BASE', 'https://api.stripe.com'), '/');
    }

    private static function key(): string
    {
        $key = Env::get('STRIPE_SECRET_KEY');
        if ($key === '') {
            throw new StripeException('STRIPE_SECRET_KEY is not set in config/.env');
        }
        return $key;
    }

    public static function isTestMode(): bool
    {
        return str_contains(Env::get('STRIPE_SECRET_KEY'), '_test_');
    }

    /** Encode nested arrays the way Stripe expects: a[b]=1&c[0][d]=2 */
    public static function encode(array $params): string
    {
        $pairs = [];
        self::flatten($params, '', $pairs);
        return implode('&', $pairs);
    }

    private static function flatten(mixed $value, string $prefix, array &$out): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                self::flatten($v, $prefix === '' ? (string)$k : $prefix . '[' . $k . ']', $out);
            }
            return;
        }
        if ($value === null) {
            return;
        }
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        $out[] = rawurlencode($prefix) . '=' . rawurlencode((string)$value);
    }

    private static function handle(\CurlHandle $ch, string $method, string $path, array $params): void
    {
        $url = self::base() . '/v1/' . ltrim($path, '/');
        $body = self::encode($params);
        if ($method === 'GET' || $method === 'DELETE') {
            if ($body !== '') {
                $url .= '?' . $body;
            }
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . self::key(),
                'Stripe-Version: ' . self::VERSION,
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: TrebHostPortal/1.0',
            ],
        ]);
    }

    private static function decode(string|bool $resp, int $status, string $curlError): array
    {
        if ($resp === false) {
            throw new StripeException('Could not reach Stripe: ' . ($curlError ?: 'network error'));
        }
        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            throw new StripeException('Unexpected response from Stripe (HTTP ' . $status . ')');
        }
        if ($status >= 400) {
            $e = new StripeException((string)($data['error']['message'] ?? 'Stripe error (HTTP ' . $status . ')'));
            $e->httpStatus = $status;
            throw $e;
        }
        return $data;
    }

    public static function request(string $method, string $path, array $params = []): array
    {
        $ch = curl_init();
        self::handle($ch, strtoupper($method), $path, $params);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return self::decode($resp, $status, curl_error($ch));
    }

    /**
     * Run several GET requests in parallel. $requests = [key => [path, params]].
     * Returns [key => decoded array, or null if that request failed].
     */
    public static function getMany(array $requests): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($requests as $key => [$path, $params]) {
            $ch = curl_init();
            self::handle($ch, 'GET', $path, $params);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $key => $ch) {
            try {
                $out[$key] = self::decode(
                    (string)curl_multi_getcontent($ch),
                    (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                    curl_error($ch)
                );
            } catch (StripeException) {
                $out[$key] = null;
            }
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    /** Fetch every item of a list endpoint (100 per page), up to $max items. */
    public static function listAll(string $path, array $params = [], int $max = 1000): array
    {
        $items = [];
        $params['limit'] = 100;
        while (true) {
            $page = self::request('GET', $path, $params);
            foreach ($page['data'] ?? [] as $item) {
                $items[] = $item;
            }
            if (empty($page['has_more']) || count($items) >= $max || empty($page['data'])) {
                break;
            }
            $params['starting_after'] = end($page['data'])['id'];
        }
        return $items;
    }

    /** Verify a webhook signature and return the decoded event. */
    public static function verifyWebhook(string $payload, string $header, string $secret, int $tolerance = 300): array
    {
        $timestamp = 0;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $timestamp = (int)$kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }
        if ($timestamp === 0 || !$signatures) {
            throw new StripeException('Invalid signature header');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $ok = false;
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                $ok = true;
            }
        }
        if (!$ok) {
            throw new StripeException('Signature mismatch');
        }
        if (abs(time() - $timestamp) > $tolerance) {
            throw new StripeException('Timestamp outside tolerance');
        }
        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new StripeException('Invalid payload');
        }
        return $event;
    }
}
