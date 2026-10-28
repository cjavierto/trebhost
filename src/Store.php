<?php
declare(strict_types=1);

namespace App;

/** Small JSON file store for settings (no database needed). Lives in /storage. */
final class Store
{
    private static function path(string $name): string
    {
        return APP_ROOT . '/storage/' . preg_replace('/[^a-z0-9_-]/i', '', $name) . '.json';
    }

    public static function get(string $name, array $default = []): array
    {
        $file = self::path($name);
        if (!is_file($file)) {
            return $default;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : $default;
    }

    public static function put(string $name, array $data): void
    {
        $file = self::path($name);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write to the storage folder. Make it writable (chmod 755 or 775).');
        }
        @chmod($tmp, 0640);
        rename($tmp, $file);
    }
}
