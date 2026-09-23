<?php
/**
 * What the workstation does when YouTube refuses a caption request: tell the
 * person at the desk, and come back when the pause is over.
 *
 * The breaker in `TranscriptFetcher` decides how long to stay away; this class
 * only acts on that decision. It raises a critical desktop notification (so a
 * refusal is noticed the moment it happens, not the morning after) and starts
 * a one-shot systemd user timer that runs the job again at the end of the
 * pause. Without it the next attempt would wait for the daily timer, and the
 * 6-hour step of the ladder would silently become 24.
 *
 * Errors that are not refusals get an alert too, and nothing else: the run
 * goes on and retries them.
 *
 * Commands run as argv lists, never through a shell. Neither is essential:
 * when `notify-send` or `systemd-run` is missing the run logs it and goes on,
 * and the daily timer is still there.
 */
declare(strict_types=1);

namespace ManorLedger\Voices;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

final class RefusalAlarm
{
    private Closure $runner;

    /** @param ?callable $runner fn(list<string> $argv): int  exit code */
    public function __construct(
        private readonly bool $notify,
        private readonly string $retryUnit,
        private readonly string $timezone = 'Europe/Rome',
        ?callable $runner = null,
        private readonly mixed $log = null,
    ) {
        $this->runner = $runner !== null ? Closure::fromCallable($runner) : Closure::fromCallable(self::run(...));
    }

    /**
     * @param array{streak: int, hours: float, until: int, error?: string} $tripped what TranscriptFetcher::tripped() returned
     * @param int $now unix time, to turn the deadline into a delay
     */
    public function raise(array $tripped, int $now): void
    {
        $at = (new DateTimeImmutable('@' . $tripped['until']))->setTimezone(new DateTimeZone($this->timezone));
        $hours = rtrim(rtrim(number_format($tripped['hours'], 1, '.', ''), '0'), '.');
        $this->say(sprintf('refusal #%d in a row: pausing %s h, next attempt %s', $tripped['streak'], $hours, $at->format('Y-m-d H:i T')));

        $scheduled = false;
        if ($this->retryUnit !== '') {
            $delay = max(60, $tripped['until'] - $now + 60);
            $code = ($this->runner)([
                'systemd-run', '--user', '--quiet',
                '--unit=manor-voices-retry-' . $tripped['until'],
                '--on-active=' . $delay . 's',
                '--timer-property=AccuracySec=1min',
                '--timer-property=RemainAfterElapsed=no',
                'systemctl', '--user', '--no-block', 'start', $this->retryUnit,
            ]);
            $scheduled = $code === 0;
            $this->say($scheduled
                ? 'retry scheduled: ' . $this->retryUnit . ' starts again at ' . $at->format('H:i')
                : 'could not schedule the retry (systemd-run exit ' . $code . '); the daily timer will try instead');
        }

        if ($this->notify) {
            $code = ($this->runner)([
                'notify-send', '--urgency=critical', '--app-name=Manor Ledger', '--icon=dialog-warning',
                sprintf('YouTube blocca i sottotitoli (blocco n. %d di fila)', $tripped['streak']),
                sprintf("Pausa di %s ore. %s alle %s del %s.\nNessuna altra richiesta fino ad allora.%s",
                    $hours, $scheduled ? 'Nuovo tentativo automatico' : 'Nessun tentativo programmato: prossimo giro del timer giornaliero, non prima',
                    $at->format('H:i'), $at->format('d/m'),
                    ($tripped['error'] ?? '') !== '' ? "\nYouTube: " . mb_substr((string)$tripped['error'], 0, 120, 'UTF-8') : ''),
            ]);
            if ($code !== 0) {
                $this->say('desktop notification failed (notify-send exit ' . $code . ')');
            }
        }
    }

    /**
     * A desktop alert for the `error` answers of a run. The run is not
     * stopped — errors are retried — this only makes sure they are seen.
     *
     * @param list<array{id: string, try: int, error: string}> $errors what TranscriptFetcher::errors() returned
     */
    public function reportErrors(array $errors): void
    {
        if ($errors === []) {
            return;
        }
        $videos = array_values(array_unique(array_column($errors, 'id')));
        $this->say(sprintf('%d caption errors on %d videos: %s', count($errors), count($videos), implode(', ', $videos)));
        if (!$this->notify) {
            return;
        }
        $lines = [];
        foreach (array_slice($errors, 0, 5) as $e) {
            $lines[] = sprintf('%s (tentativo %d): %s', $e['id'], $e['try'], mb_substr($e['error'], 0, 80, 'UTF-8'));
        }
        if (count($errors) > 5) {
            $lines[] = sprintf('… e altri %d', count($errors) - 5);
        }
        $code = ($this->runner)([
            'notify-send', '--urgency=critical', '--app-name=Manor Ledger', '--icon=dialog-warning',
            sprintf('Errori sui sottotitoli: %d su %d video', count($errors), count($videos)),
            "Il job continua e ritenta. Dettagli:\n" . implode("\n", $lines),
        ]);
        if ($code !== 0) {
            $this->say('desktop notification failed (notify-send exit ' . $code . ')');
        }
    }

    private function say(string $line): void
    {
        if (is_callable($this->log)) {
            ($this->log)($line);
        }
    }

    /** @param list<string> $argv */
    private static function run(array $argv): int
    {
        $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            return 127;
        }

        return proc_close($process);
    }
}
