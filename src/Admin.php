<?php
declare(strict_types=1);

namespace App;

final class Admin
{
    /* ---------- guards ---------- */

    private static function guard(): void
    {
        if (!Auth::check()) {
            redirect('/admin/login');
        }
    }

    private static function post(): void
    {
        self::guard();
        if (!Auth::csrfValid()) {
            http_response_code(403);
            render('error', ['message' => 'Your session expired. Go back, refresh the page and try again.'], 'admin');
            exit;
        }
    }

    private static function clientOr404(string $id): array
    {
        $client = Billing::client($id);
        if (!$client) {
            http_response_code(404);
            render('error', ['message' => 'That client was not found.', 'back' => '/admin'], 'admin');
            exit;
        }
        return $client;
    }

    /* ---------- login ---------- */

    public static function loginForm(): void
    {
        if (Auth::check()) {
            redirect('/admin');
        }
        render('login', ['error' => null, 'locked' => Auth::tooManyAttempts()], null);
    }

    public static function loginPost(): void
    {
        if (!Auth::csrfValid()) {
            redirect('/admin/login');
        }
        if (Auth::tooManyAttempts()) {
            render('login', ['error' => null, 'locked' => true], null);
            return;
        }
        if (!Auth::checkPassword((string)($_POST['password'] ?? ''))) {
            Auth::recordFailure();
            usleep(600000);
            render('login', ['error' => 'That password is not correct.', 'locked' => false], null);
            return;
        }
        Auth::clearFailures();
        Auth::login();
        redirect('/admin');
    }

    public static function logout(): void
    {
        self::post();
        Auth::logout();
        redirect('/admin/login');
    }

    /* ---------- clients ---------- */

    public static function dashboard(): void
    {
        self::guard();
        $snap = Billing::snapshot();
        $serviceCount = 0;
        foreach ($snap['rows'] as $row) {
            $serviceCount += count($row['services']);
        }
        render('dashboard', ['snap' => $snap, 'serviceCount' => $serviceCount, 'title' => 'Clients', 'nav' => 'clients']);
    }

    public static function addClient(): void
    {
        self::post();
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if ($name === '') {
            flash('error', 'Enter the client or business name.');
            redirect('/admin');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'That email address does not look right.');
            redirect('/admin');
        }
        $customer = StripeApi::request('POST', 'customers', [
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'metadata' => ['app' => Billing::TAG, 'link_v' => 0, 'link_at' => time()],
        ]);
        flash('success', $name . ' was added. Now add their services below.');
        redirect('/admin/clients/' . $customer['id']);
    }

    public static function client(string $id): void
    {
        self::guard();
        $client = self::clientOr404($id);
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
        render('client', [
            'client' => $client,
            'rows' => $rows,
            'due' => $due,
            'payUrl' => Billing::payUrl($client),
            'title' => (string)($client['name'] ?? 'Client'),
            'nav' => 'clients',
        ]);
    }

    public static function editClient(string $id): void
    {
        self::post();
        $client = self::clientOr404($id);
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if ($name === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            flash('error', 'Enter a name and a valid email address.');
            redirect('/admin/clients/' . $client['id']);
        }
        StripeApi::request('POST', 'customers/' . $client['id'], ['name' => $name, 'email' => $email]);
        flash('success', 'Client details saved.');
        redirect('/admin/clients/' . $client['id']);
    }

    public static function rotateLink(string $id): void
    {
        self::post();
        $client = self::clientOr404($id);
        StripeApi::request('POST', 'customers/' . $client['id'], [
            'metadata' => ['link_v' => Billing::linkVersion($client) + 1, 'link_at' => time()],
        ]);
        flash('success', 'New payment link created. The old link no longer works.');
        redirect('/admin/clients/' . $client['id']);
    }

    /* ---------- services ---------- */

    private static function parseAnchor(string $input, string $interval): int|string
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $d = \DateTime::createFromFormat('!d/m/Y', $input, $tz);
        $errors = \DateTime::getLastErrors();
        if (!$d || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            return 'Enter the first charge date like 24/11/2026, or leave it blank.';
        }
        $d->setTime(9, 0);
        $ts = $d->getTimestamp();
        if ($ts <= time() + 86400) {
            return 'The first charge date must be at least a day in the future.';
        }
        $limit = (new \DateTime('now', $tz))->modify($interval === 'year' ? '+1 year -1 day' : '+1 month -1 day')->getTimestamp();
        if ($ts > $limit) {
            return 'Stripe only allows the first charge up to one ' . $interval . ' from today.';
        }
        return $ts;
    }

    public static function addService(string $id): void
    {
        self::post();
        $client = self::clientOr404($id);
        $back = '/admin/clients/' . $client['id'];

        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $priceIn = str_replace([',', '$', ' '], '', (string)($_POST['price'] ?? ''));
        $interval = (($_POST['cycle'] ?? 'monthly') === 'yearly') ? 'year' : 'month';
        $dateIn = trim((string)($_POST['first_charge'] ?? ''));

        if ($name === '') {
            flash('error', 'Enter a service name.');
            redirect($back);
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $priceIn) || (float)$priceIn < 0.5) {
            flash('error', 'Enter a price like 49.00 (minimum 0.50).');
            redirect($back);
        }
        $ids = Billing::serviceIds($client);
        if (count($ids) >= 12) {
            flash('error', 'A client can have up to 12 services.');
            redirect($back);
        }
        $anchor = null;
        if ($dateIn !== '') {
            $parsed = self::parseAnchor($dateIn, $interval);
            if (is_string($parsed)) {
                flash('error', $parsed);
                redirect($back);
            }
            $anchor = $parsed;
        }

        $product = StripeApi::request('POST', 'products', [
            'name' => $name,
            'description' => $desc !== '' ? $desc : null,
            'metadata' => ['app' => Billing::TAG, 'customer' => $client['id']],
        ]);
        $price = StripeApi::request('POST', 'prices', [
            'product' => $product['id'],
            'currency' => Billing::currency(),
            'unit_amount' => (int)round(((float)$priceIn) * 100),
            'recurring' => ['interval' => $interval],
            'metadata' => ['app' => Billing::TAG, 'customer' => $client['id'], 'anchor' => $anchor],
        ]);
        $ids[] = $price['id'];
        StripeApi::request('POST', 'customers/' . $client['id'], ['metadata' => ['svc' => implode(',', $ids)]]);

        flash('success', $name . ' was added. It now shows on the client\'s payment page.');
        redirect($back);
    }

    public static function removeService(string $id): void
    {
        self::post();
        $client = self::clientOr404($id);
        $back = '/admin/clients/' . $client['id'];
        $priceId = (string)($_POST['price_id'] ?? '');
        $ids = Billing::serviceIds($client);
        if (!in_array($priceId, $ids, true)) {
            flash('error', 'That service was not found.');
            redirect($back);
        }

        // Stop future charges if the client is already being billed for it.
        foreach (Billing::subsFor($client['id']) as $sub) {
            if ($sub['price'] === $priceId && in_array($sub['status'], Billing::LIVE, true) && !$sub['cancel_at_period_end']) {
                StripeApi::request('POST', 'subscriptions/' . $sub['id'], ['cancel_at_period_end' => true]);
            }
        }
        try {
            $price = StripeApi::request('POST', 'prices/' . $priceId, ['active' => false]);
            if (!empty($price['product']) && is_string($price['product'])) {
                StripeApi::request('POST', 'products/' . $price['product'], ['active' => false]);
            }
        } catch (StripeException) {
            // archiving is best-effort
        }
        $ids = array_values(array_diff($ids, [$priceId]));
        StripeApi::request('POST', 'customers/' . $client['id'], ['metadata' => ['svc' => implode(',', $ids)]]);

        flash('success', 'Service removed. If it was being billed, it will not renew after the current period.');
        redirect($back);
    }

    /* ---------- payments ---------- */

    private static function paymentsData(): array
    {
        $range = Payments::range((string)($_GET['range'] ?? 'this_month'));
        $snap = Billing::snapshot();
        $rows = Payments::rows($range, $snap);

        $collected = 0;
        $collectedCount = 0;
        $failedCount = 0;
        $failedAmount = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'paid') {
                $collected += $r['amount'] - $r['refunded'];
                $collectedCount++;
            } elseif ($r['status'] === 'failed') {
                $failedCount++;
                $failedAmount += $r['amount'];
            }
        }
        $outstanding = 0;
        $outstandingClients = 0;
        $nextRetry = null;
        foreach ($snap['rows'] as $row) {
            $owes = false;
            foreach ($row['services'] as $svc) {
                $st = Billing::state($svc, $row['subs']);
                if ($st['state'] !== 'active') {
                    $outstanding += $svc['amount'];
                    $owes = true;
                }
                $retry = $st['sub']['retry_at'] ?? null;
                if ($retry && $retry > time() && ($nextRetry === null || $retry < $nextRetry)) {
                    $nextRetry = $retry;
                }
            }
            if ($owes) {
                $outstandingClients++;
            }
        }
        return compact('range', 'rows', 'collected', 'collectedCount', 'failedCount', 'failedAmount', 'outstanding', 'outstandingClients', 'nextRetry');
    }

    public static function payments(): void
    {
        self::guard();
        render('payments', self::paymentsData() + ['title' => 'Payments', 'nav' => 'payments']);
    }

    public static function paymentsCsv(): void
    {
        self::guard();
        $data = self::paymentsData();
        $name = 'payments-' . $data['range']['key'] . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Client', 'Service', 'Amount (' . cur() . ')', 'Status', 'Card', 'Note', 'Receipt']);
        foreach ($data['rows'] as $r) {
            // Guard against spreadsheet formula injection from client-supplied text.
            $safe = fn(string $v) => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
            fputcsv($out, [
                date('Y-m-d', $r['created']), $safe($r['client']), $safe($r['service']),
                number_format($r['amount'] / 100, 2, '.', ''), $r['status'], $r['card'], $safe($r['note']), $r['receipt'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    /* ---------- settings ---------- */

    public static function settings(): void
    {
        self::guard();
        $account = null;
        try {
            $account = StripeApi::request('GET', 'account');
        } catch (StripeException) {
            // shown as "Could not reach Stripe"
        }
        render('settings', [
            'account' => $account,
            'webhook' => Store::get('webhook', []),
            'title' => 'Settings',
            'nav' => 'settings',
        ]);
    }

    private static function saveSettings(array $changes): void
    {
        Store::put('settings', array_merge(Store::get('settings', []), $changes));
        settings(true);
    }

    public static function saveBusiness(): void
    {
        self::post();
        $name = trim((string)($_POST['business_name'] ?? ''));
        $email = trim((string)($_POST['billing_email'] ?? ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a business name and a valid billing email.');
            redirect('/admin/settings');
        }
        self::saveSettings([
            'business_name' => mb_substr($name, 0, 80),
            'billing_email' => $email,
            'gst_number' => mb_substr(trim((string)($_POST['gst_number'] ?? '')), 0, 30),
            'footer_message' => mb_substr(trim((string)($_POST['footer_message'] ?? '')), 0, 500),
        ]);
        flash('success', 'Business details saved.');
        redirect('/admin/settings');
    }

    public static function saveNotifications(): void
    {
        self::post();
        self::saveSettings([
            'notify_fail_admin' => isset($_POST['notify_fail_admin']),
            'notify_success_admin' => isset($_POST['notify_success_admin']),
            'remind_yearly' => isset($_POST['remind_yearly']),
            'notify_fail_client' => isset($_POST['notify_fail_client']),
        ]);
        flash('success', 'Notification preferences saved.');
        redirect('/admin/settings');
    }

    public static function savePassword(): void
    {
        self::post();
        $current = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        if (!Auth::checkPassword($current)) {
            flash('error', 'Your current password is not correct.');
        } elseif (mb_strlen($new) < 12) {
            flash('error', 'The new password must be at least 12 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'The new passwords do not match.');
        } else {
            Auth::setPassword($new);
            Auth::login(); // keep this browser signed in; every other session ends
            flash('success', 'Password updated. Other devices have been signed out.');
        }
        redirect('/admin/settings');
    }

    public static function signOutAll(): void
    {
        self::post();
        Auth::bumpSessionVersion();
        Auth::logout();
        redirect('/admin/login');
    }
}
