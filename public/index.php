<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Admin;
use App\Pay;
use App\StripeException;
use App\Webhook;

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/') ?: '/';

try {
    $m = [];
    $isGet = $method === 'GET' || $method === 'HEAD';
    $isPost = $method === 'POST';

    // The sign-in page and client pay pages must never be cached or indexed.
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');

    if ($path === '/') {
        redirect('/admin');
    } elseif ($path === '/webhook/stripe' && $isPost) {
        Webhook::handle();
    } elseif (preg_match('#^/c/([A-Za-z0-9_.\-]+)$#', $path, $m) && $isGet) {
        Pay::page($m[1]);
    } elseif (preg_match('#^/c/([A-Za-z0-9_.\-]+)/checkout$#', $path, $m) && $isPost) {
        Pay::checkout($m[1]);
    } elseif (preg_match('#^/c/([A-Za-z0-9_.\-]+)/complete$#', $path, $m) && $isGet) {
        Pay::complete($m[1]);
    } elseif (preg_match('#^/c/([A-Za-z0-9_.\-]+)/portal$#', $path, $m) && $isPost) {
        Pay::portal($m[1]);
    } elseif ($path === '/admin/login') {
        $isPost ? Admin::loginPost() : Admin::loginForm();
    } elseif ($path === '/admin/logout' && $isPost) {
        Admin::logout();
    } elseif ($path === '/admin' && $isGet) {
        Admin::dashboard();
    } elseif ($path === '/admin/clients' && $isPost) {
        Admin::addClient();
    } elseif (preg_match('#^/admin/clients/(cus_[A-Za-z0-9]+)$#', $path, $m) && $isGet) {
        Admin::client($m[1]);
    } elseif (preg_match('#^/admin/clients/(cus_[A-Za-z0-9]+)/(edit|services|services/remove|link)$#', $path, $m) && $isPost) {
        match ($m[2]) {
            'edit' => Admin::editClient($m[1]),
            'services' => Admin::addService($m[1]),
            'services/remove' => Admin::removeService($m[1]),
            'link' => Admin::rotateLink($m[1]),
        };
    } elseif ($path === '/admin/payments' && $isGet) {
        Admin::payments();
    } elseif ($path === '/admin/payments.csv' && $isGet) {
        Admin::paymentsCsv();
    } elseif ($path === '/admin/settings' && $isGet) {
        Admin::settings();
    } elseif ($path === '/admin/settings/business' && $isPost) {
        Admin::saveBusiness();
    } elseif ($path === '/admin/settings/notifications' && $isPost) {
        Admin::saveNotifications();
    } elseif ($path === '/admin/settings/password' && $isPost) {
        Admin::savePassword();
    } elseif ($path === '/admin/settings/sign-out-all' && $isPost) {
        Admin::signOutAll();
    } else {
        http_response_code(404);
        render('link_invalid', ['title' => 'Page not found', 'notFound' => true], 'public');
    }
} catch (StripeException $e) {
    error_log('Stripe error on ' . $path . ': ' . $e->getMessage());
    http_response_code(500);
    $admin = str_starts_with($path, '/admin');
    render('error', [
        'message' => $admin ? 'Stripe said: ' . $e->getMessage() : 'We could not reach our payment provider just now. Please try again in a minute.',
        'back' => $admin ? '/admin' : null,
    ], $admin && App\Auth::check() ? 'admin' : 'public');
} catch (Throwable $e) {
    error_log('Error on ' . $path . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    $admin = str_starts_with($path, '/admin');
    render('error', [
        'message' => App\Env::get('APP_DEBUG') === '1' ? $e->getMessage() : 'Something went wrong. Please try again, or contact support if it keeps happening.',
    ], 'public');
}
