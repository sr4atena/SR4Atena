<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Voices;

use ManorLedger\Tests\TempDirTrait;
use ManorLedger\Voices\ThumbnailStore;
use PHPUnit\Framework\TestCase;

/** Thumbnails are ours: downloaded once, verified, served from our own host. */
final class ThumbnailStoreTest extends TestCase
{
    use TempDirTrait;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->makeTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private static function jpeg(): string
    {
        return "\xFF\xD8\xFF\xE0" . str_repeat('x', 2000);
    }

    public function testDownloadsOnceAndSkipsWhatIsAlreadyThere(): void
    {
        $calls = 0;
        $transport = static function (array $requests) use (&$calls): array {
            $calls += count($requests);

            return array_map(static fn (): array => ['status' => 200, 'body' => self::jpeg(), 'headers' => []], $requests);
        };
        $store = new ThumbnailStore($this->dir . '/yt', $transport);
        $videos = [
            ['id' => 'AAAAAAAAAAA', 'thumbnailUrl' => 'https://i.ytimg.com/vi/AAAAAAAAAAA/mqdefault.jpg'],
            ['id' => 'CCCCCCCCCCC', 'thumbnailUrl' => 'https://i.ytimg.com/vi/CCCCCCCCCCC/mqdefault.jpg'],
        ];
        self::assertSame(['AAAAAAAAAAA' => true, 'CCCCCCCCCCC' => true], $store->store($videos));
        self::assertSame(2, $calls);
        self::assertSame(0644, fileperms($store->path('AAAAAAAAAAA')) & 0777, 'the web process runs as another user');

        self::assertSame(['AAAAAAAAAAA' => true, 'CCCCCCCCCCC' => true], $store->store($videos));
        self::assertSame(2, $calls, 'a second run downloads nothing');
    }

    public function testOnlyRealJpegsAreWritten(): void
    {
        $store = new ThumbnailStore($this->dir . '/yt', static fn (array $r): array => [
            ['status' => 200, 'body' => '<html>not an image</html>' . str_repeat(' ', 2000), 'headers' => []],
        ]);
        self::assertSame(['AAAAAAAAAAA' => false], $store->store([['id' => 'AAAAAAAAAAA', 'thumbnailUrl' => 'https://i.ytimg.com/x.jpg']]));
        self::assertFileDoesNotExist($store->path('AAAAAAAAAAA'));
        self::assertTrue(ThumbnailStore::isJpeg(self::jpeg()));
        self::assertFalse(ThumbnailStore::isJpeg("\xFF\xD8\xFF"), 'a truncated file is not an image either');
    }

    public function testMalformedIdsAndPlainHttpAreRefused(): void
    {
        $store = new ThumbnailStore($this->dir . '/yt', static fn (array $r): array => [['status' => 200, 'body' => self::jpeg()]]);
        $result = $store->store([
            ['id' => '../../etc/passwd', 'thumbnailUrl' => 'https://i.ytimg.com/x.jpg'],
            ['id' => 'AAAAAAAAAAA', 'thumbnailUrl' => 'http://i.ytimg.com/x.jpg'],
        ]);
        self::assertSame(['AAAAAAAAAAA' => false], $result);
    }

    public function testPruneKeepsTheCurrentListAndTheRecentPast(): void
    {
        $store = new ThumbnailStore($this->dir . '/yt', static fn (array $r): array => []);
        mkdir($this->dir . '/yt', 0755, true);
        foreach (['AAAAAAAAAAA', 'CCCCCCCCCCC', 'DDDDDDDDDDD'] as $id) {
            file_put_contents($store->path($id), self::jpeg());
        }
        touch($store->path('CCCCCCCCCCC'), time() - 40 * 86400);
        touch($store->path('DDDDDDDDDDD'), time() - 40 * 86400);

        self::assertSame(1, $store->prune(['AAAAAAAAAAA', 'CCCCCCCCCCC'], 30));
        self::assertFileExists($store->path('AAAAAAAAAAA'));
        self::assertFileExists($store->path('CCCCCCCCCCC'), 'still in the top ten');
        self::assertFileDoesNotExist($store->path('DDDDDDDDDDD'));
    }
}
