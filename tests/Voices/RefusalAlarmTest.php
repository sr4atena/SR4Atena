<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Voices;

use ManorLedger\Voices\RefusalAlarm;
use PHPUnit\Framework\TestCase;

final class RefusalAlarmTest extends TestCase
{
    /** @var list<list<string>> */
    private array $ran = [];

    private function alarm(bool $notify, string $unit, int $exit = 0): RefusalAlarm
    {
        return new RefusalAlarm($notify, $unit, 'Europe/Rome', function (array $argv) use ($exit): int {
            $this->ran[] = $argv;

            return $exit;
        });
    }

    public function testSchedulesTheRetryAtTheEndOfThePauseAndAlertsTheDesk(): void
    {
        // 2026-09-23 06:00 UTC = 08:00 in Rome; six hours on, 14:00.
        $now = 1_790_143_200;
        $this->alarm(true, 'manor-voices.service')->raise(['streak' => 1, 'hours' => 6.0, 'until' => $now + 21_600], $now);

        self::assertCount(2, $this->ran);
        [$retry, $alert] = $this->ran;
        self::assertSame('systemd-run', $retry[0]);
        self::assertContains('--on-active=21660s', $retry);
        self::assertSame(['systemctl', '--user', '--no-block', 'start', 'manor-voices.service'], array_slice($retry, -5));
        self::assertSame('notify-send', $alert[0]);
        self::assertContains('--urgency=critical', $alert);
        self::assertStringContainsString('blocco n. 1', implode(' ', $alert));
        self::assertStringContainsString('Pausa di 6 ore', implode(' ', $alert));
        self::assertStringContainsString('14:00', implode(' ', $alert));
    }

    public function testWithoutARetryUnitItOnlyAlerts(): void
    {
        $this->alarm(true, '')->raise(['streak' => 3, 'hours' => 24.0, 'until' => 2_000_086_400], 2_000_000_000);

        self::assertCount(1, $this->ran);
        self::assertSame('notify-send', $this->ran[0][0]);
        self::assertStringContainsString('Nessun tentativo programmato', implode(' ', $this->ran[0]));
    }

    public function testErrorsAreOnlyReportedWithOneAlert(): void
    {
        $this->alarm(true, 'manor-voices.service')->reportErrors([
            ['id' => 'aaaaaaaaaa1', 'try' => 1, 'error' => 'HTTPError: 500'],
            ['id' => 'aaaaaaaaaa1', 'try' => 2, 'error' => 'HTTPError: 500'],
            ['id' => 'bbbbbbbbbb2', 'try' => 1, 'error' => 'TimeoutError'],
        ]);

        self::assertCount(1, $this->ran, 'one alert, and no retry scheduled: errors never stop or reschedule the job');
        self::assertSame('notify-send', $this->ran[0][0]);
        self::assertStringContainsString('3 su 2 video', implode(' ', $this->ran[0]));
        self::assertStringContainsString('bbbbbbbbbb2 (tentativo 1): TimeoutError', implode(' ', $this->ran[0]));
    }

    public function testNoErrorsNoAlert(): void
    {
        $this->alarm(true, 'manor-voices.service')->reportErrors([]);
        self::assertSame([], $this->ran);
    }

    public function testAFailedScheduleIsSaidInTheAlert(): void
    {
        $this->alarm(true, 'manor-voices.service', 1)->raise(['streak' => 2, 'hours' => 12.0, 'until' => 2_000_043_200], 2_000_000_000);

        self::assertStringContainsString('Nessun tentativo programmato', implode(' ', $this->ran[1]));
    }
}
