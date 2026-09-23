<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Voices;

use ManorLedger\Tests\TempDirTrait;
use ManorLedger\Voices\ArchiveSource;
use PHPUnit\Framework\TestCase;

final class ArchiveSourceTest extends TestCase
{
    use TempDirTrait;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = $this->makeTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function archive(array $videos, int $mtime): string
    {
        $path = $this->dir . '/archive.json';
        file_put_contents($path, json_encode(['videos' => $videos]));
        touch($path, $mtime);

        return $path;
    }

    public function testTheMostWatchedComeFirstAndTheTakeIsRespected(): void
    {
        $path = $this->archive([
            'AAAAAAAAAAA' => ['views' => 10], 'BBBBBBBBBBB' => ['views' => 128487],
            'CCCCCCCCCCC' => ['views' => 500], 'not-an-id' => ['views' => 9_999_999],
        ], 1_000_000);
        $r = (new ArchiveSource($path, 72, 2, static fn (): int => 1_000_000 + 3600))->ids();

        self::assertSame(['BBBBBBBBBBB', 'CCCCCCCCCCC'], $r['ids'], 'malformed ids never reach the API');
        self::assertStringContainsString('2 candidates from the archive (3 in it', $r['note']);
    }

    public function testAStaleOrMissingOrBrokenArchiveAddsNothing(): void
    {
        $path = $this->archive(['AAAAAAAAAAA' => ['views' => 10]], 1_000_000);
        $stale = (new ArchiveSource($path, 72, 150, static fn (): int => 1_000_000 + 73 * 3600))->ids();
        self::assertSame([], $stale['ids']);
        self::assertStringContainsString('search only', $stale['note']);

        self::assertSame([], (new ArchiveSource($this->dir . '/nope.json'))->ids()['ids']);

        file_put_contents($path, '{not json');
        self::assertSame([], (new ArchiveSource($path, 72, 150, static fn (): int => (int)filemtime($path)))->ids()['ids']);
    }
}
