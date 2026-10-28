<?php
declare(strict_types=1);

use App\Auth;
use App\Env;
use App\Store;

function e(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Cents -> "$49.00" (NZD) */
function money(int $cents): string
{
    return ($cents < 0 ? '-' : '') . '$' . number_format(abs($cents) / 100, 2);
}

/** Currency code for display, e.g. "NZD" (from CURRENCY in .env) */
function cur(): string
{
    return strtoupper(App\Env::get('CURRENCY', 'nzd'));
}

function dmy(?int $ts): string
{
    return $ts ? date('d/m/Y', $ts) : '—';
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function base_url(): string
{
    $configured = Env::get('APP_URL');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function redirect(string $to, int $code = 302): never
{
    header('Location: ' . $to, true, $code);
    exit;
}

function flash(string $type, string $message): void
{
    setcookie('th_flash', json_encode([$type, $message]), [
        'expires' => time() + 60, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https(),
    ]);
}

function take_flash(): ?array
{
    if (empty($_COOKIE['th_flash'])) {
        return null;
    }
    $data = json_decode((string)$_COOKIE['th_flash'], true);
    setcookie('th_flash', '', ['expires' => time() - 3600, 'path' => '/']);
    unset($_COOKIE['th_flash']);
    return (is_array($data) && count($data) === 2) ? $data : null;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Auth::csrfToken()) . '">';
}

/** Business settings (editable in Admin > Settings), cached per request. */
function settings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = array_merge([
            'business_name' => Env::get('BUSINESS_NAME', 'TrebHost'),
            'billing_email' => Env::get('BILLING_EMAIL', 'billing@trebhost.co.nz'),
            'gst_number' => '',
            'footer_message' => '',
            'notify_fail_admin' => true,
            'notify_success_admin' => false,
            'remind_yearly' => true,
            'notify_fail_client' => true,
        ], Store::get('settings', []));
    }
    return $cache;
}

function initials(string $name): string
{
    $words = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($words, 0, 2) as $w) {
        $out .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return $out !== '' ? $out : '?';
}

/** Render a view, wrapped in a layout ('admin', 'public' or null). */
function render(string $view, array $vars = [], ?string $layout = 'admin'): void
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_ROOT . '/views/' . $view . '.php';
    $content = ob_get_clean();
    if ($layout !== null) {
        require APP_ROOT . '/views/layout_' . $layout . '.php';
    } else {
        echo $content;
    }
}

function badge(string $kind, ?string $label = null): string
{
    $map = [
        'active' => ['ok', 'Active'],
        'paid' => ['ok', 'Paid'],
        'connected' => ['ok', 'Connected'],
        'due' => ['warn', 'Awaiting payment'],
        'pending' => ['warn', 'Pending'],
        'failed' => ['err', 'Payment failed'],
        'refunded' => ['muted', 'Refunded'],
        'none' => ['muted', 'No services'],
    ];
    [$class, $text] = $map[$kind] ?? ['muted', ucfirst($kind)];
    return '<span class="badge ' . $class . '"><span class="dot" aria-hidden="true"></span>' . e($label ?? $text) . '</span>';
}

function logo(int $size = 28): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 28 28" aria-hidden="true"><rect width="28" height="28" rx="7" fill="#2563EB"></rect><rect x="7" y="8" width="14" height="3" rx="1.5" fill="#FFFFFF"></rect><rect x="7" y="12.5" width="14" height="3" rx="1.5" fill="#FFFFFF" opacity="0.75"></rect><rect x="7" y="17" width="9" height="3" rx="1.5" fill="#FFFFFF" opacity="0.5"></rect></svg>';
}

function icon(string $name, int $size = 16): string
{
    $paths = [
        'clients' => '<circle cx="9" cy="8" r="3.5"></circle><path d="M2.5 20a6.5 6.5 0 0 1 13 0"></path><path d="M16 4.5a3.5 3.5 0 0 1 0 7"></path><path d="M18 14a6.5 6.5 0 0 1 3.5 6"></path>',
        'payments' => '<rect x="2.5" y="5" width="19" height="14" rx="2"></rect><path d="M2.5 10h19"></path><path d="M6.5 15h4"></path>',
        'settings' => '<circle cx="12" cy="12" r="3"></circle><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"></path>',
        'search' => '<circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path>',
        'plus' => '<path d="M12 5v14M5 12h14"></path>',
        'external' => '<path d="M14 4h6v6"></path><path d="M20 4l-9 9"></path><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"></path>',
        'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"></rect><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"></path>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"></path>',
        'download' => '<path d="M12 4v11"></path><path d="M7 11l5 5 5-5"></path><path d="M5 20h14"></path>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M3 7l9 6 9-6"></path>',
        'unlink' => '<path d="M9.5 14.5l-1.8 1.8a3 3 0 0 1-4.2-4.2l3-3a3 3 0 0 1 4.2 0"></path><path d="M14.5 9.5l1.8-1.8a3 3 0 0 1 4.2 4.2l-3 3a3 3 0 0 1-4.2 0"></path><path d="M3 3l18 18"></path>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function asset(string $file): string
{
    return '/assets/' . $file . '?v=' . (@filemtime(APP_ROOT . '/public/assets/' . $file) ?: 1);
}

function dashboard_url(string $path = ''): string
{
    return 'https://dashboard.stripe.com' . (App\StripeApi::isTestMode() ? '/test' : '') . $path;
}
