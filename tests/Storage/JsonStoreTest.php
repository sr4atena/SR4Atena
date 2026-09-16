<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Storage;

use ManorLedger\Storage\JsonStore;
use PHPUnit\Framework\TestCase;

final class JsonStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/manor-jsonstore-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            rmdir($this->dir);
        }
    }

    public function testMissingFileReadsAsNull(): void
    {
        $store = new JsonStore($this->dir . '/missing.json');
        self::assertNull($store->read());
        self::assertNull($store->mtime());
    }

    public function testInvalidJsonReadsAsNull(): void
    {
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/bad.json', '{not json');
        self::assertNull((new JsonStore($this->dir . '/bad.json'))->read());
        file_put_contents($this->dir . '/scalar.json', '42');
        self::assertNull((new JsonStore($this->dir . '/scalar.json'))->read());
    }

    public function testWriteCreatesDirectoryAndRoundTripsWithTightPermissions(): void
    {
        $path = $this->dir . '/doc.json';
        $store = new JsonStore($path);
        $store->write(['a' => 1, 'b' => [1.0, null, 'è']]);

        self::assertSame(['a' => 1, 'b' => [1.0, null, 'è']], $store->read());
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame(0700, fileperms($this->dir) & 0777);
        self::assertIsInt($store->mtime());
        self::assertSame([], glob($this->dir . '/.doc.json.*') ?: [], 'temp file must not survive the rename');
    }

    public function testOverwriteReplacesWholeDocument(): void
    {
        $store = new JsonStore($this->dir . '/doc.json');
        $store->write(['first' => true]);
        $store->write(['second' => true]);
        self::assertSame(['second' => true], $store->read());
    }
}
