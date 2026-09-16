<?php
/**
 * Deterministic pre-filter over YouTube comments, run before any model call.
 *
 * Measured on the real data (PLAN-voices §9): the most-liked substantial
 * comments are almost all addressed to the creator ("love your videos"), not
 * to the game. Dropping them here is cheaper, reproducible and testable, and
 * it keeps the prompt free of noise that would otherwise steer the summary.
 *
 * Pure functions on purpose: this part must never need a model to be correct.
 */
declare(strict_types=1);

namespace ManorLedger\Voices;

final class CommentFilter
{
    public const MIN_LENGTH = 40;
    public const MAX_KEPT   = 60;

    /**
     * Chatter aimed at the channel rather than at the game, in the languages
     * the top ten actually uses (English, Spanish, Portuguese, Italian).
     * Short tokens carry word boundaries so "first" does not swallow
     * "the first time I played".
     */
    private const CREATOR_PATTERNS = [
        '/\b(i )?lov(e|ed) (your|ur) (videos?|vids?|channel|content)\b/u',
        '/\byour (videos?|vids?|channel|content) (are|is)\b/u',
        '/\b(am|are) (a |an )?(big |huge )?fan\b/u',
        '/\bmy childhood\b/u',
        '/\bnotification (squad|gang|crew)\b/u',
        '/\b(pin|heart) (me|this)\b/u',
        '/\bwho(\'|’)?s (still )?watching\b/u',
        '/\b(first|firsttt+|early)\b\W*$/u',
        '/\bsubscrib(e|ed)\b/u',
        '/\bday \d+ of asking\b/u',
        '/\b(me encantan|amo) (tus|sus) (videos|vídeos)\b/u',
        '/\bsaludos\b/u',
        '/\b(amo|adoro) (seus|os seus) (videos|vídeos)\b/u',
        '/\bsou (seu|sua) fã\b/u',
        '/\bparabéns pelo canal\b/u',
        '/\b(bei|bellissimi) video\b/u',
        '/\bti adoro\b/u',
    ];

    /**
     * @param  list<array{text: string, likes?: int}> $comments raw API texts
     * @return list<array{text: string, likes: int}>  kept, most liked first
     */
    public function filter(array $comments, int $max = self::MAX_KEPT): array
    {
        $kept = [];
        foreach ($comments as $comment) {
            $text = self::normalise((string)($comment['text'] ?? ''));
            if (!self::isUseful($text)) {
                continue;
            }
            $kept[] = ['text' => $text, 'likes' => max(0, (int)($comment['likes'] ?? 0))];
        }
        // Stable on ties: equal likes keep API order, which is relevance.
        usort($kept, static fn (array $a, array $b): int => $b['likes'] <=> $a['likes']);

        return array_slice($kept, 0, max(0, $max));
    }

    /** Collapses whitespace and undoes the HTML escaping the API applies. */
    public static function normalise(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function isUseful(string $text): bool
    {
        if (mb_strlen($text, 'UTF-8') < self::MIN_LENGTH) {
            return false;
        }
        // Emoji, punctuation and "🔥🔥🔥 W game" carry no extractable opinion.
        if (preg_match_all('/\p{L}/u', $text) < 20) {
            return false;
        }
        $lower = mb_strtolower($text, 'UTF-8');
        foreach (self::CREATOR_PATTERNS as $pattern) {
            if (preg_match($pattern, $lower) === 1) {
                return false;
            }
        }

        return true;
    }
}
