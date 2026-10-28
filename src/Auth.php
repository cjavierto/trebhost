<?php
declare(strict_types=1);

namespace App;

/**
 * Stateless admin login (signed cookie) + signed client pay links.
 * Password: stored hashed in storage/admin.json once changed in Settings,
 * otherwise ADMIN_PASSWORD from config/.env is used.
 */
final class Auth
{
    private const COOKIE = 'th_admin';
    private const LOGIN_COOKIE = 'th_lt';
    private const TTL = 604800; // 7 days

    public static function secret(): string
    {
        $s = Env::get('APP_SECRET');
        if (strlen($s) < 32) {
            throw new \RuntimeException('APP_SECRET in config/.env must be a random string of at least 32 characters.');
        }
        return $s;
    }

    private static function sign(string $data): string
    {
        return hash_hmac('sha256', $data, self::secret());
    }

    private static function b64e(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64d(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/'));
    }

    private static function cookieOpts(int $expires): array
    {
        return ['expires' => $expires, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax'];
    }

    /* ---------- password ---------- */

    private static function record(): array
    {
        return Store::get('admin', []);
    }

    public static function sessionVersion(): int
    {
        return (int)(self::record()['session_version'] ?? 1);
    }

    public static function checkPassword(string $password): bool
    {
        $rec = self::record();
        if (!empty($rec['hash'])) {
            return password_verify($password, $rec['hash']);
        }
        $env = Env::get('ADMIN_PASSWORD');
        return $env !== '' && hash_equals(hash('sha256', $env), hash('sha256', $password));
    }

    public static function setPassword(string $password): void
    {
        $rec = self::record();
        $rec['hash'] = password_hash($password, PASSWORD_DEFAULT);
        $rec['session_version'] = self::sessionVersion() + 1;
        Store::put('admin', $rec);
    }

    public static function bumpSessionVersion(): void
    {
        $rec = self::record();
        $rec['session_version'] = self::sessionVersion() + 1;
        Store::put('admin', $rec);
    }

    /* ---------- login throttling ---------- */

    public static function tooManyAttempts(): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $data = Store::get('ratelimit', []);
        $recent = array_filter($data[$ip] ?? [], fn($t) => $t > time() - 900);
        return count($recent) >= 8;
    }

    public static function recordFailure(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $data = Store::get('ratelimit', []);
        foreach ($data as $k => $times) {
            $data[$k] = array_values(array_filter($times, fn($t) => $t > time() - 900));
            if (!$data[$k]) {
                unset($data[$k]);
            }
        }
        $data[$ip][] = time();
        Store::put('ratelimit', $data);
    }

    public static function clearFailures(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $data = Store::get('ratelimit', []);
        unset($data[$ip]);
        Store::put('ratelimit', $data);
    }

    /* ---------- session cookie ---------- */

    public static function login(): void
    {
        $payload = self::b64e(json_encode(['exp' => time() + self::TTL, 'v' => self::sessionVersion()]));
        $value = $payload . '.' . self::sign($payload);
        setcookie(self::COOKIE, $value, self::cookieOpts(time() + self::TTL));
        $_COOKIE[self::COOKIE] = $value;
    }

    public static function logout(): void
    {
        setcookie(self::COOKIE, '', self::cookieOpts(time() - 3600));
        unset($_COOKIE[self::COOKIE]);
    }

    public static function check(): bool
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($raw) || !str_contains($raw, '.')) {
            return false;
        }
        [$payload, $sig] = explode('.', $raw, 2);
        if (!hash_equals(self::sign($payload), $sig)) {
            return false;
        }
        $data = json_decode(self::b64d($payload), true);
        return is_array($data)
            && ($data['exp'] ?? 0) > time()
            && (int)($data['v'] ?? 0) === self::sessionVersion();
    }

    /* ---------- CSRF ---------- */

    /** Token for logged-in forms: derived from the session cookie. */
    public static function csrfToken(): string
    {
        $raw = (string)($_COOKIE[self::COOKIE] ?? '');
        if ($raw !== '') {
            return substr(self::sign('csrf|' . $raw), 0, 40);
        }
        // Login form: double-submit cookie
        $lt = (string)($_COOKIE[self::LOGIN_COOKIE] ?? '');
        if ($lt === '') {
            $lt = bin2hex(random_bytes(16));
            setcookie(self::LOGIN_COOKIE, $lt, self::cookieOpts(time() + 3600));
            $_COOKIE[self::LOGIN_COOKIE] = $lt;
        }
        return substr(self::sign('login|' . $lt), 0, 40);
    }

    public static function csrfValid(): bool
    {
        $given = (string)($_POST['_csrf'] ?? '');
        return $given !== '' && hash_equals(self::csrfToken(), $given);
    }

    /* ---------- client pay links ---------- */

    private static function paySig(string $customerId, int $version): string
    {
        return substr(self::sign('pay|' . $customerId . '|' . $version), 0, 24);
    }

    public static function payToken(string $customerId, int $version): string
    {
        return $customerId . '.' . $version . '.' . self::paySig($customerId, $version);
    }

    /** @return array{id:string,version:int}|null */
    public static function parsePayToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || !preg_match('/^cus_[A-Za-z0-9]+$/', $parts[0]) || !ctype_digit($parts[1])) {
            return null;
        }
        $version = (int)$parts[1];
        if (!hash_equals(self::paySig($parts[0], $version), $parts[2])) {
            return null;
        }
        return ['id' => $parts[0], 'version' => $version];
    }
}
