<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Auth;

use InvalidArgumentException;
use ManorLedger\Auth\UserStore;
use ManorLedger\Support\FrozenClock;
use ManorLedger\Tests\TempDirTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UserStoreTest extends TestCase
{
    use TempDirTrait;

    private const HASH = '$2y$12$yGUXrnIknbVLhMj020NQEOIhUgm3k114MWBeDl8j6IRKE3mWEVlTK';
    private UserStore $store;
    private string $file;

    protected function setUp(): void
    {
        $this->file = $this->makeTempDir() . '/nested/users.json';
        $this->store = new UserStore($this->file, new FrozenClock(1_789_533_904 /* 2026-09-16T04:45:04Z */));
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    public function testAddFindAndPermissions(): void
    {
        $record = $this->store->add('alice', self::HASH, 'owner');
        self::assertSame('2026-09-16T04:45:04Z', $record['createdAt']);
        self::assertNull($record['lastLoginAt']);
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->file)), -4));
        self::assertSame('0700', substr(sprintf('%o', fileperms(dirname($this->file))), -4));
        self::assertSame('owner', $this->store->find('alice')['role']);
        self::assertNull($this->store->find('bob'));
        self::assertNull($this->store->find('Alice'), 'lookups are strict lower-case');
    }

    public function testDuplicateIsRejected(): void
    {
        $this->store->add('alice', self::HASH);
        $this->expectException(RuntimeException::class);
        $this->store->add('alice', self::HASH);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUsernames(): iterable
    {
        yield 'too short' => ['ab'];
        yield 'upper case' => ['Alice'];
        yield 'space' => ['al ice'];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'path traversal' => ['../etc'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidUsernames')]
    public function testInvalidUsernameIsRejected(string $username): void
    {
        self::assertFalse(UserStore::isValidUsername($username));
        $this->expectException(InvalidArgumentException::class);
        $this->store->add($username, self::HASH);
    }

    public function testInvalidRoleAndHashAreRejected(): void
    {
        try {
            $this->store->add('alice', self::HASH, 'admin');
            self::fail('role should be rejected');
        } catch (InvalidArgumentException) {
            self::assertNull($this->store->find('alice'));
        }
        $this->expectException(InvalidArgumentException::class);
        $this->store->add('alice', 'plaintext-not-a-hash');
    }

    public function testMutations(): void
    {
        $this->store->add('alice', self::HASH);
        $this->store->add('bob', self::HASH, 'owner');
        $newHash = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->store->updatePassword('alice', $newHash);
        $this->store->setTotp('alice', 'JBSWY3DPEHPK3PXP');
        $this->store->recordLogin('alice');
        $alice = $this->store->find('alice');
        self::assertSame($newHash, $alice['hash']);
        self::assertSame('JBSWY3DPEHPK3PXP', $alice['totpSecret']);
        self::assertSame('2026-09-16T04:45:04Z', $alice['lastLoginAt']);
        $this->store->recordLogin('alice', 56_666_666);
        self::assertSame(56_666_666, $this->store->find('alice')['totpLastStep']);
        $this->store->setTotp('alice', null);
        self::assertNull($this->store->find('alice')['totpSecret']);
        self::assertNull($this->store->find('alice')['totpLastStep'], 'a new secret starts with a clean replay marker');
        self::assertSame(['alice', 'bob'], array_column($this->store->all(), 'username'));
        $this->store->remove('bob');
        self::assertNull($this->store->find('bob'));
        self::assertCount(1, $this->store->all());
        self::assertSame([], glob(dirname($this->file) . '/*.tmp') ?: [], 'no temp files left behind');
    }

    public function testUnknownUserMutationsThrow(): void
    {
        $this->expectException(RuntimeException::class);
        $this->store->updatePassword('ghost', self::HASH);
    }
}
