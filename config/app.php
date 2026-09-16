<?php
/**
 * Application settings. Every value can be overridden with an environment
 * variable (MANOR_*), which is how the VPS deployment points to /var/lib.
 * Nothing secret lives here: the Roblox key is a file outside the web root.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$env  = static fn (string $key, string|int|float $default) => getenv($key) !== false ? getenv($key) : $default;
$dataDir = rtrim((string)$env('MANOR_DATA_DIR', $root . '/data'), '/');
// State the web process owns and writes: sessions, login throttling, user
// records, audit log. It is a separate directory because in production the
// refresh job and the web pool run as different users; the job owns the data
// and the pool may only read it, so the pool needs somewhere of its own.
$stateDir = rtrim((string)$env('MANOR_STATE_DIR', $dataDir), '/');

return [
    'app' => [
        'name'      => 'Manor Ledger',
        'game'      => 'The Locust\'s Manor',
        'universeId'=> (int)$env('MANOR_UNIVERSE_ID', 10674300622),
        'host'      => (string)$env('MANOR_HOST', 'localhost'),   // expected Host header (CSRF origin check)
        'timezone'  => 'Europe/Rome',
        'debug'     => (bool)$env('MANOR_DEBUG', 0),
    ],
    'paths' => [
        'data'       => $dataDir,
        'state'      => $stateDir,
        'cache'      => $dataDir . '/cache',
        'snapshots'  => $dataDir . '/snapshots',
        'history'    => $dataDir . '/history.json',
        'dashboard'  => $dataDir . '/dashboard.json',
        'users'      => $stateDir . '/users.json',
        'throttle'   => $stateDir . '/throttle',
        'sessions'   => $stateDir . '/sessions',
        'authLog'    => $stateDir . '/auth.log',
        'apiKey'     => (string)$env('MANOR_API_KEY_FILE', $dataDir . '/api-key'),
        'metrics'    => $root . '/config/metrics.json',
        'dimensions' => $root . '/config/dimensions.json',
        'glossary'   => $root . '/config/glossary.json',
    ],
    'roblox' => [
        'baseUrl'     => 'https://apis.roblox.com/analytics-query-api/',
        // Measured: 30 requests per calendar minute, shared by every key of the owner.
        'windowLimit' => 18,   // fallback until an x-ratelimit header is seen
        'windowSecs'  => 60,
        'reserve'     => 6,
        'concurrency' => 2,
        'maxTries'    => 3,
        'maxPolls'    => 8,
        'pollWait'    => 1.5,
        'deadline'    => 600,
    ],
    'economics' => [
        'devexUsdPerRobux' => 0.0038,  // Roblox DevEx rate
        'royaltyShare'     => 0.17,    // publisher share paid out before the developer
        'multiples'        => ['conservative' => 18, 'base' => 30],
        'plateauShares'    => [0.06, 0.10, 0.15],
    ],
    'auth' => [
        'idleTimeout'     => 1800,
        'absoluteTimeout' => 43200,
        'maxFailures'     => 5,
        'lockoutSeconds'  => 900,
        'cookieName'      => '__Host-manor_session',
    ],
];
