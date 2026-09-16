<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Roblox;

use ManorLedger\Roblox\RateBudget;
use PHPUnit\Framework\TestCase;

final class RateBudgetTest extends TestCase
{
    private string $dir;
    private float $clock = 1_000_000.0;
    /** @var list<float> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/manor-budget-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    private function budget(bool $allowSleep, int $limit = 3, int $windowSecs = 60, int $reserve = 6): RateBudget
    {
        return new RateBudget(
            $this->dir . '/.budget.json',
            $allowSleep,
            $limit,
            $windowSecs,
            $reserve,
            fn (): float => $this->clock,
            function (float $secs): void {
                $this->sleeps[] = $secs;
                $this->clock += $secs;
            },
        );
    }

    public function testFallbackWindowGrantsLimitThenRefusesWithoutSleeping(): void
    {
        $budget = $this->budget(false, 3);
        self::assertTrue($budget->take());
        self::assertTrue($budget->take());
        self::assertTrue($budget->take());
        self::assertFalse($budget->take(), 'fourth token in the same window must be refused');
        self::assertSame([], $this->sleeps);

        $this->clock += 60;
        self::assertTrue($budget->take(), 'a new window starts after windowSecs');
    }

    public function testStateIsSharedBetweenInstancesOnTheSameFile(): void
    {
        $a = $this->budget(false, 5);
        $b = $this->budget(false, 5);
        $granted = 0;
        for ($i = 0; $i < 10; $i++) {
            if (($i % 2 === 0 ? $a : $b)->take()) {
                $granted++;
            }
        }
        self::assertSame(5, $granted, 'two processes must share one window of 5 tokens');
        self::assertFileExists($this->dir . '/.budget.json');
        self::assertSame(0o600, fileperms($this->dir . '/.budget.json') & 0o777);
    }

    public function testCliModeSleepsUntilTheWindowResets(): void
    {
        $budget = $this->budget(true, 2, 30);
        self::assertTrue($budget->take());
        self::assertTrue($budget->take());
        self::assertTrue($budget->take(), 'in CLI mode take() waits instead of refusing');
        self::assertCount(1, $this->sleeps);
        self::assertEqualsWithDelta(30.25, $this->sleeps[0], 0.001);
    }

    public function testObservedHeadersDriveTheBudgetAndReserveIsRespected(): void
    {
        $budget = $this->budget(false, 100, 60, 6);
        $budget->observe(8, 20);
        self::assertTrue($budget->take());   // 8 -> 7
        self::assertTrue($budget->take());   // 7 -> 6
        self::assertFalse($budget->take(), 'at the reserve the window is considered spent');

        $this->clock += 21;
        self::assertTrue($budget->take(), 'after the declared reset the fallback window takes over');
    }

    public function testObserveKeepsTheMostPessimisticValues(): void
    {
        $budget = $this->budget(false, 100, 60, 0);
        $budget->observe(10, 10);
        $budget->observe(25, 5);   // out-of-order response: must not raise remaining or shorten reset
        $state = json_decode((string)file_get_contents($this->dir . '/.budget.json'), true);
        self::assertSame(10, $state['remaining']);
        self::assertEqualsWithDelta($this->clock + 10, $state['resetAt'], 0.001);

        $budget->observe(null, null);
        self::assertSame(10, json_decode((string)file_get_contents($this->dir . '/.budget.json'), true)['remaining']);
    }

    public function testBlockForAndExhaustedPauseEveryTaker(): void
    {
        $budget = $this->budget(false, 10);
        $budget->blockFor(12.0);
        self::assertFalse($budget->take());
        $this->clock += 12.5;
        self::assertTrue($budget->take());

        $other = $this->budget(true, 10, 60);
        $other->exhausted();
        self::assertTrue($other->take(), 'CLI mode waits the whole window out');
        self::assertEqualsWithDelta(60.25, end($this->sleeps), 0.001);
    }

    public function testAllowsSleepReflectsConstructorFlag(): void
    {
        self::assertTrue($this->budget(true)->allowsSleep());
        self::assertFalse($this->budget(false)->allowsSleep());
    }
}
