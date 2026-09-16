<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Support;

use InvalidArgumentException;
use ManorLedger\Support\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testTypedGetters(): void
    {
        $config = new Config(['auth' => ['idleTimeout' => 1800, 'flag' => '1', 'name' => 'x'], 'list' => [1, 2]]);
        self::assertSame(1800, $config->int('auth.idleTimeout'));
        self::assertTrue($config->bool('auth.flag'));
        self::assertSame('x', $config->string('auth.name'));
        self::assertSame([1, 2], $config->array('list'));
        self::assertSame('fallback', $config->get('auth.missing', 'fallback'));
        self::assertTrue($config->has('auth.name'));
        self::assertFalse($config->has('auth.nope'));
        $this->expectException(InvalidArgumentException::class);
        $config->int('auth.name');
    }

    public function testLoadsRealConfigWithEnvOverride(): void
    {
        putenv('MANOR_DATA_DIR=/var/lib/manor/');
        try {
            $config = Config::load(__DIR__ . '/../../config/app.php');
            self::assertSame('/var/lib/manor/users.json', $config->string('paths.users'));
            self::assertSame('__Host-manor_session', $config->string('auth.cookieName'));
            self::assertSame(1800, $config->int('auth.idleTimeout'));
        } finally {
            putenv('MANOR_DATA_DIR');
        }
    }
}
