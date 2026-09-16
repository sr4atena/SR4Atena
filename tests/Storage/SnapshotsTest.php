<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Storage;

use ManorLedger\Storage\Snapshots;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SnapshotsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/manor-snapshots-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testWriteListReadRoundTrip(): void
    {
        $snap = new Snapshots($this->dir);
        self::assertSame([], $snap->list());
        $doc = ['fetchedAt' => 123, 'results' => ['X' => ['status' => 'ok', 'series' => []]]];
        $snap->write('2026-09-02', $doc);
        $snap->write('2026-09-01', $doc);

        self::assertSame(['2026-09-01', '2026-09-02'], $snap->list());
        self::assertSame($doc, $snap->read('2026-09-02'));
        self::assertNull($snap->read('2026-09-03'));
        self::assertSame(0600, fileperms($snap->pathFor('2026-09-01')) & 0777);
        self::assertSame("\x1f\x8b", substr((string)file_get_contents($snap->pathFor('2026-09-01')), 0, 2), 'gzip magic');
    }

    public function testPruneRemovesOnlyOlderThanKeepDays(): void
    {
        $snap = new Snapshots($this->dir);
        foreach (['2026-08-01', '2026-08-25', '2026-08-26', '2026-09-01'] as $d) {
            $snap->write($d, ['d' => $d]);
        }
        self::assertSame(1, $snap->prune(7, '2026-09-01'));
        self::assertSame(['2026-08-25', '2026-08-26', '2026-09-01'], $snap->list());
        self::assertSame(0, $snap->prune(7, '2026-09-01'));
    }

    public function testRejectsMalformedDates(): void
    {
        $this->expectException(RuntimeException::class);
        (new Snapshots($this->dir))->write('../etc', []);
    }
}
