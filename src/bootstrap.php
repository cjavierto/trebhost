<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require APP_ROOT . '/src/helpers.php';

App\Env::load(APP_ROOT . '/config/.env');
date_default_timezone_set(App\Env::get('TIMEZONE', 'Pacific/Auckland'));

ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/error.log');
ini_set('display_errors', App\Env::get('APP_DEBUG') === '1' ? '1' : '0');

mb_internal_encoding('UTF-8');
