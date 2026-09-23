<?php
/**
 * Assembles data/voices.json: the ten most watched videos about the game, each
 * summarised, plus the synthesis across them.
 *
 * Degradation is the design: a missing transcript falls back to comments, a
 * failed summary is an honest card, a failed synthesis keeps yesterday's with
 * `status: stale` and its own date. Nothing here ever throws away a previous
 * good file.
 */
declare(strict_types=1);

namespace ManorLedger\Voices;

use Closure;
use ManorLedger\Storage\JsonStore;
use Throwable;

final class VoicesBuilder
{
    private Closure $log;
    private Closure $now;

    /**
     * @param list<string>          $queries
     * @param array<string, string> $primaryModels model id configured for `summary` and `synthesis`
     */
    public function __construct(
        private readonly YouTubeClient $youtube,
        private readonly CommentFilter $comments,
        private readonly TranscriptFetcher $transcripts,
        private readonly ThumbnailStore $thumbnails,
        private readonly VideoSummarizer $summarizer,
        private readonly Synthesizer $synthesizer,
        private readonly JsonStore $output,
        private readonly string $summaryCacheDir,
        private readonly array $queries = YouTubeClient::DEFAULT_QUERIES,
        private readonly array $primaryModels = [],
        private readonly string $host = 'workstation',
        ?callable $log = null,
        ?callable $now = null,
        private readonly ?ArchiveSource $archive = null,
    ) {
        $this->log = $log !== null ? Closure::fromCallable($log) : static fn (string $l): null => null;
        $this->now = $now !== null ? Closure::fromCallable($now) : static fn (): int => time();
    }

    /**
     * @param array{only?: list<string>, remodel?: bool, resynthesize?: bool, dryRun?: bool,
     *              refreshTranscripts?: bool, topN?: int} $options
     * @return array<string, mixed> the document written to disk
     */
    public function run(array $options = []): array
    {
        $only = $options['only'] ?? [];
        $topN = (int)($options['topN'] ?? 15);
        $previous = $this->output->read() ?? [];
        if (($options['dryRun'] ?? false) === true) {
            return $this->plan($topN, $previous);
        }

        $now = gmdate('Y-m-d\TH:i:s\Z', ($this->now)());
        $this->log('searching YouTube: ' . implode(' / ', $this->queries));
        $extra = $this->archive?->ids() ?? ['ids' => [], 'note' => ''];
        if ($this->archive !== null) {
            $this->log('archive: ' . $extra['note']);
        }
        $found = $this->youtube->topVideos($this->queries, $topN, 2, $extra['ids']);
        $videos = $found['videos'];
        $this->log(sprintf('%d candidates, %d excluded as non-Roblox, %d kept (%d found only through the archive)',
            $found['stats']['candidates'], $found['stats']['excludedNonRoblox'], count($videos), $found['stats']['fromArchive'] ?? 0));

        $stored = $this->thumbnails->store($videos);
        foreach (array_keys(array_filter($stored, static fn (bool $ok): bool => !$ok)) as $id) {
            $this->log($id . ': thumbnail unavailable, the view will show a placeholder');
        }
        $transcriptStatuses = array_fill_keys(TranscriptFetcher::STATUSES, 0);
        $regenerated = 0;
        $withTranscript = 0;
        $rows = [];
        foreach ($videos as $video) {
            $id = (string)$video['id'];
            $selected = $only === [] || in_array($id, $only, true);
            $comments = $this->youtube->comments($id, CommentFilter::MAX_KEPT);
            $kept = $this->comments->filter($comments['comments']);
            $transcript = $this->transcripts->fetch($id, ($options['refreshTranscripts'] ?? false) !== true);
            $transcriptStatuses[$transcript['status']]++;
            if ($transcript['status'] === 'ok') {
                $withTranscript++;
            }
            $cached = $this->summarizer->cached($id);
            // A mixed cache is not a result: --remodel redoes whatever the
            // fallback wrote, and keeps everything the primary model produced.
            $wrongModel = ($options['remodel'] ?? false) === true && $cached !== null
                && ($cached['model'] ?? null) !== ($this->primaryModels['summary'] ?? null);
            if ($wrongModel) {
                $this->log($id . ': cached summary came from ' . ($cached['model'] ?? 'nothing') . ', asking the primary model again');
            } elseif ($cached !== null && $selected && $only === []) {
                $this->log($id . ': summary cached');
            }
            $summary = $selected
                ? $this->summarizer->summarize($video, $transcript, $kept, $now, $only === [] && !$wrongModel)
                : ($cached ?? self::failedSummary($now));
            $produced = $selected && ($cached === null || $wrongModel || $only !== []);
            $regenerated += $produced && $summary['status'] === 'ok' ? 1 : 0;
            $this->log(sprintf('%s: %s · transcript %s (%d chars) · comments %d→%d · summary %s (%s)',
                $id, mb_substr((string)$video['title'], 0, 48, 'UTF-8'), $transcript['status'],
                $transcript['chars'], $comments['fetched'], count($kept), $summary['status'],
                $summary['model'] ?? ($summary['error'] ?? 'nessun modello')));

            $rows[] = [
                'id' => $id, 'title' => $video['title'], 'channel' => $video['channel'],
                'channelId' => $video['channelId'], 'publishedAt' => $video['publishedAt'],
                'views' => $video['views'], 'likes' => $video['likes'], 'commentCount' => $video['commentCount'],
                'url' => $video['url'],
                'thumbnail' => '/media/yt/' . $id . '.jpg',
                'transcript' => ['status' => $transcript['status'], 'language' => $transcript['language'],
                                 'generated' => $transcript['generated'], 'chars' => $transcript['chars']],
                'comments' => ['fetched' => $comments['fetched'], 'kept' => count($kept)],
                'summary' => $summary,
            ];
        }

        $this->log('transcripts: ' . implode(', ', array_map(
            static fn (string $s, int $n): string => $n . ' ' . $s,
            array_keys($transcriptStatuses),
            $transcriptStatuses,
        )));
        if ($this->transcripts->isBlocked()) {
            // Not a fault of this machine and not one of the videos: the address
            // is in a corner for a while. Say it plainly, because the page will
            // show summaries that nobody refreshed tonight.
            $this->log('WARNING: YouTube refused caption requests from this address, so no further '
                . 'request was sent. It never says how long a refusal lasts: our own cooldown holds '
                . 'until ' . gmdate('Y-m-d H:i', (int)$this->transcripts->blockedUntil()) . ' UTC. '
                . 'Tonight\'s summaries come from the cache and from comments; run again after that, '
                . 'or with --clear-block once YouTube answers again.');
        }
        if ($rows !== [] && $transcriptStatuses['error'] * 2 > count($rows)) {
            // Captions disabled is a property of a video; `error` never is.
            $this->log('WARNING: more than half the transcripts failed with "error". That is this machine or a '
                . 'throttle, not the videos: check the virtualenv (make voices-venv) before trusting this file.');
        }

        $document = [
            'generatedAt' => $now,
            'host' => $this->host,
            'queries' => array_values($this->queries),
            'models' => ['summary' => $this->primaryModels['summary'] ?? null,
                         'synthesis' => $this->primaryModels['synthesis'] ?? null, 'fellBackTo' => null],
            'stats' => ['candidates' => $found['stats']['candidates'],
                        'excludedNonRoblox' => $found['stats']['excludedNonRoblox'],
                        'withTranscript' => $withTranscript],
            'videos' => $rows,
            'synthesis' => [],
        ];
        // A synthesis of summaries that have since been rewritten is not the
        // synthesis of this file: redo it whenever anything below it changed.
        $document['synthesis'] = $this->synthesis($rows, $previous, $now,
            ($options['resynthesize'] ?? false) === true || $regenerated > 0);
        $document['models'] = $this->models($document);
        $this->output->write($document);
        $this->prune(array_column($rows, 'id'));
        $this->log('written ' . $this->output->path());

        return $document;
    }

    /** @param list<array<string, mixed>> $rows */
    private function synthesis(array $rows, array $previous, string $now, bool $force): array
    {
        $summarised = array_values(array_filter($rows, static fn (array $r): bool => $r['summary']['status'] === 'ok'));
        $old = is_array($previous['synthesis'] ?? null) ? $previous['synthesis'] : [];
        if ($summarised === []) {
            $this->log('synthesis: no summarised video, keeping the previous one');

            return $old === [] ? self::emptySynthesis($now) : ['status' => 'stale'] + $old;
        }
        if (!$force && !$this->synthesisIsStale($old, $summarised, $now)) {
            $this->log('synthesis: still current, reused');

            return $old;
        }
        try {
            $synthesis = $this->synthesizer->synthesize($summarised, $now);
            $this->log('synthesis: ' . count($synthesis['improvements']) . ' improvements, '
                . count($synthesis['likes']) . ' likes, model ' . $synthesis['model']);

            return $synthesis;
        } catch (Throwable $e) {
            $this->log('synthesis failed: ' . $e->getMessage());

            return $old === [] ? self::emptySynthesis($now) : ['status' => 'stale'] + $old;
        }
    }

    /** Regenerated once a day, or whenever the list of videos it was based on changed. */
    private function synthesisIsStale(array $old, array $summarised, string $now): bool
    {
        if (($old['status'] ?? '') !== 'ok') {
            return true;
        }
        if (substr((string)($old['generatedAt'] ?? ''), 0, 10) !== substr($now, 0, 10)) {
            return true;
        }
        $considered = array_map('strval', $old['videosConsidered'] ?? []);
        sort($considered);
        $current = array_column($summarised, 'id');
        sort($current);

        return $considered !== $current;
    }

    /** The models that actually produced the text, so the page can say when the fallback ran. */
    private function models(array $document): array
    {
        $models = $document['models'];
        $used = [];
        foreach ($document['videos'] as $video) {
            $model = $video['summary']['model'] ?? null;
            if (is_string($model) && $model !== '') {
                $used[$model] = ($used[$model] ?? 0) + 1;
            }
        }
        if ($used !== []) {
            // The model that wrote most of the cards is the one to name.
            arsort($used);
            $models['summary'] = (string)array_key_first($used);
        }
        $synthesisModel = $document['synthesis']['model'] ?? null;
        if (is_string($synthesisModel) && $synthesisModel !== '') {
            $models['synthesis'] = $synthesisModel;
            $used[$synthesisModel] = ($used[$synthesisModel] ?? 0) + 1;
        }
        foreach (array_keys($used) as $model) {
            if ($model !== ($this->primaryModels['summary'] ?? null) && $model !== ($this->primaryModels['synthesis'] ?? null)) {
                $models['fellBackTo'] = $model;
            }
        }

        return $models;
    }

    /** A video that leaves the top ten keeps its cache for 30 days, then it goes. */
    private function prune(array $keepIds, int $days = 30): void
    {
        $cutoff = time() - $days * 86400;
        $removed = $this->thumbnails->prune($keepIds, $days);
        foreach (glob($this->summaryCacheDir . '/*.json') ?: [] as $file) {
            if (!in_array(basename($file, '.json'), $keepIds, true) && (int)filemtime($file) < $cutoff && unlink($file)) {
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->log('pruned ' . $removed . ' stale cache files');
        }
    }

    /** @return array<string, mixed> what a real run would do, without doing any of it */
    private function plan(int $topN, array $previous): array
    {
        $cached = count(glob($this->summaryCacheDir . '/*.json') ?: []);
        $this->log('dry run, nothing will be sent');
        $this->log(sprintf('YouTube: %d search pages (%d units), 1 videos.list, up to %d commentThreads ≈ %d units',
            count($this->queries) * 2, count($this->queries) * 2 * 100, $topN, count($this->queries) * 200 + 1 + $topN));
        $this->log(sprintf('transcripts: up to %d runs of tools/transcript.py', $topN));
        $this->log(sprintf('model: up to %d summaries (%d already cached) + 1 synthesis', $topN, $cached));
        $this->log('output: ' . $this->output->path() . ' (previous run: ' . ($previous['generatedAt'] ?? 'none') . ')');

        return $previous;
    }

    /** Same keys as a good summary, so the view never has to test for their presence. */
    public static function failedSummary(string $now, ?string $error = null, array $basedOn = []): array
    {
        return ['status' => 'failed', 'generatedAt' => $now, 'model' => null, 'basedOn' => $basedOn, 'tone' => null,
                'likes' => [], 'improvements' => [], 'oneLine' => null, 'quotes' => []]
            + ($error !== null ? ['error' => $error] : []);
    }

    private static function emptySynthesis(string $now): array
    {
        return ['status' => 'stale', 'generatedAt' => $now, 'model' => null, 'videosConsidered' => [],
                'likes' => [], 'improvements' => [], 'verdict' => '', 'timeline' => []];
    }

    private function log(string $line): void
    {
        ($this->log)($line);
    }
}
