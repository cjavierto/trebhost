<?php
/**
 * Daily cron job: emails clients their payment link 14 days before a yearly
 * service (domain, SSL...) renews. Safe to run more than once a day.
 *
 * cPanel > Cron Jobs, once a day:
 *   /usr/local/bin/php /home/YOURUSER/pay/cron/reminders.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Billing;
use App\Mailer;
use App\Store;
use App\StripeApi;

$s = settings();
if (!$s['remind_yearly']) {
    echo "Yearly reminders are switched off in Settings.\n";
    exit(0);
}

$snap = Billing::snapshot();
$sent = Store::get('reminded', []);
$now = time();
$count = 0;

foreach ($snap['clients'] as $client) {
    if (empty($client['email'])) {
        continue;
    }
    foreach ($snap['rows'][$client['id']]['subs'] as $sub) {
        if ($sub['status'] !== 'active' || $sub['interval'] !== 'year' || $sub['cancel_at_period_end'] || !$sub['period_end']) {
            continue;
        }
        $days = ($sub['period_end'] - $now) / 86400;
        $key = $sub['id'] . ':' . $sub['period_end'];
        if ($days < 0 || $days > 14 || isset($sent[$key])) {
            continue;
        }
        $service = $snap['prices'][$sub['price']] ?? null;
        $label = $service ? $service['name'] : 'your yearly service';
        $amount = $service ? ' (' . money($service['amount']) . ')' : '';
        $ok = Mailer::send(
            $client['email'],
            'Upcoming renewal: ' . $label,
            "Hi " . ($client['name'] ?? 'there') . ",\n\n" .
            "$label$amount renews on " . date('d/m/Y', $sub['period_end']) . ".\n" .
            "Your saved card will be charged automatically. You can check your services or update your card here:\n" .
            Billing::payUrl($client) . "\n\n" .
            $s['business_name'] . "\n"
        );
        if ($ok) {
            $sent[$key] = $now;
            $count++;
        }
    }
}

// forget old entries
foreach ($sent as $k => $t) {
    if ($t < $now - 90 * 86400) {
        unset($sent[$k]);
    }
}
Store::put('reminded', $sent);
echo "Reminders sent: $count\n";
