<?php
/** Front controller. nginx maps every non-asset URL here; php -S uses it as router script. */
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $static = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($static !== __DIR__ . '/' && is_file($static) && !str_ends_with($static, '.php')) {
        return false; // let the built-in server stream the asset
    }
}

require dirname(__DIR__) . '/src/autoload.php';

ManorLedger\Http\Kernel::boot(dirname(__DIR__))->run();
