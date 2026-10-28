<?php
declare(strict_types=1);

namespace App;

/** Payment history for the Admin > Payments page, built from Stripe charges. */
final class Payments
{
    public static function rangeOptions(): array
    {
        $now = time();
        $lastMonth = strtotime('first day of last month 00:00:00', $now);
        return [
            'this_month' => date('F Y', $now),
            'last_month' => date('F Y', $lastMonth),
            '90d' => 'Last 90 days',
            'fy' => 'This financial year',
        ];
    }

    /** @return array{from:int,to:int,label:string,month:?string} */
    public static function range(string $key): array
    {
        $now = time();
        $opts = self::rangeOptions();
        if (!isset($opts[$key])) {
            $key = 'this_month';
        }
        switch ($key) {
            case 'last_month':
                $from = strtotime('first day of last month 00:00:00', $now);
                $to = strtotime('first day of this month 00:00:00', $now);
                $month = date('F', $from);
                break;
            case '90d':
                $from = $now - 90 * 86400;
                $to = $now + 60;
                $month = null;
                break;
            case 'fy': // NZ financial year: 1 April - 31 March
                $year = (int)date('Y', $now) - ((int)date('n', $now) < 4 ? 1 : 0);
                $from = (int)mktime(0, 0, 0, 4, 1, $year);
                $to = (int)mktime(0, 0, 0, 4, 1, $year + 1);
                $month = null;
                break;
            default:
                $from = strtotime('first day of this month 00:00:00', $now);
                $to = strtotime('first day of next month 00:00:00', $now);
                $month = date('F', $from);
        }
        return ['key' => $key, 'from' => (int)$from, 'to' => (int)$to, 'label' => $opts[$key], 'month' => $month];
    }

    /**
     * @param array $snapshot Billing::snapshot()
     * @return array[] rows, newest first
     */
    public static function rows(array $range, array $snapshot): array
    {
        $names = [];
        foreach ($snapshot['clients'] as $c) {
            $names[$c['id']] = (string)($c['name'] ?? $c['email'] ?? $c['id']);
        }
        $charges = StripeApi::listAll('charges', [
            'created' => ['gte' => $range['from'], 'lt' => $range['to']],
            'expand' => ['data.invoice'],
        ]);

        $rows = [];
        foreach ($charges as $ch) {
            $custId = is_array($ch['customer'] ?? null) ? ($ch['customer']['id'] ?? '') : (string)($ch['customer'] ?? '');
            if (!isset($names[$custId])) {
                continue; // not one of our clients
            }
            $inv = is_array($ch['invoice'] ?? null) ? $ch['invoice'] : [];
            $line = $inv['lines']['data'][0] ?? [];
            $svc = $snapshot['prices'][$line['price']['id'] ?? ''] ?? null;
            $serviceName = $svc['name'] ?? (string)($line['description'] ?? $ch['description'] ?? 'Payment');
            $serviceDesc = $svc['description'] ?? '';

            $amount = (int)$ch['amount'];
            $refunded = (int)($ch['amount_refunded'] ?? 0);
            $status = (string)$ch['status'];
            if ($status === 'succeeded') {
                $key = ($refunded >= $amount && $amount > 0) ? 'refunded' : 'paid';
            } elseif ($status === 'failed') {
                $key = 'failed';
            } else {
                $key = 'pending';
            }

            $card = $ch['payment_method_details']['card'] ?? null;
            $rows[] = [
                'created' => (int)$ch['created'],
                'customer_id' => $custId,
                'client' => $names[$custId],
                'service' => $serviceName,
                'detail' => $serviceDesc,
                'amount' => $amount,
                'refunded' => $refunded,
                'status' => $key,
                'note' => $key === 'failed'
                    ? (string)($ch['failure_message'] ?? '')
                    : ($refunded > 0 && $key !== 'refunded' ? 'Partially refunded ' . money($refunded) : ($key === 'refunded' ? 'Refunded ' . money($refunded) : '')),
                'card' => $card ? ucfirst((string)($card['brand'] ?? 'Card')) . ' •••• ' . ($card['last4'] ?? '') : '—',
                'receipt' => $ch['receipt_url'] ?? null,
            ];
        }
        usort($rows, fn($a, $b) => $b['created'] <=> $a['created']);
        return $rows;
    }
}
