<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Voices;

use ManorLedger\Voices\CommentFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure logic, so it is tested pure: no model is allowed to be needed here. */
final class CommentFilterTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function comments(): iterable
    {
        yield 'substantial opinion about the game' => [
            'the stamina bar runs out way too fast, you can barely cross the hall before the monster catches you', true];
        yield 'too short' => ['the game is scary and fun', false];
        yield 'exactly at the threshold' => [str_repeat('a', 20) . ' il gioco è molto bello davvero', true];
        yield 'pure emoji' => ['🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥', false];
        yield 'punctuation and digits only' => ['1234567890 !!!! ???? .... ,,,, ---- ++++ **** #### @@@@', false];
        yield 'love your videos' => ['I love your videos so much, you have been my childhood youtuber forever', false];
        yield 'fan of the channel' => ['bro I am a huge fan of this channel, please keep uploading horror games', false];
        yield 'first comment' => ['I am watching this at three in the morning and I am first', false];
        yield 'first inside a real opinion is kept' => [
            'the first time I played the manor the doors would not open, that bug is still there today', true];
        yield 'notification squad' => ['who else is here from the notification squad at 3am, love you all so much', false];
        yield 'pin me' => ['can you pin me please, I have watched every single one of these videos twice', false];
        yield 'spanish creator chatter' => ['me encantan tus videos, saludos desde Argentina para todo el canal', false];
        yield 'portuguese creator chatter' => ['amo seus vídeos, sou seu fã desde o começo do canal, continue assim', false];
        yield 'spanish opinion about the game' => [
            'el juego es divertido pero los monstruos se quedan parados delante de los armarios todo el rato', true];
    }

    #[DataProvider('comments')]
    public function testKeepsOnlyWhatCanCarryAnOpinion(string $text, bool $kept): void
    {
        self::assertSame($kept, CommentFilter::isUseful(CommentFilter::normalise($text)));
    }

    public function testUnescapesAndCollapsesWhitespace(): void
    {
        self::assertSame(
            "the manor's doors don't open and it\u{2019}s frustrating",
            CommentFilter::normalise("the manor&#39;s doors\n don&#39;t   open\tand it&rsquo;s frustrating"),
        );
    }

    public function testOrdersByLikesAndCaps(): void
    {
        $long = 'the key mechanic for opening the doors is genuinely clever and it made the manor feel connected';
        $comments = [
            ['text' => $long . ' one', 'likes' => 3],
            ['text' => 'I love your videos, you are my childhood, keep going forever please', 'likes' => 999],
            ['text' => $long . ' two', 'likes' => 41],
            ['text' => 'short one', 'likes' => 500],
        ];
        $kept = (new CommentFilter())->filter($comments);
        self::assertCount(2, $kept);
        self::assertSame(41, $kept[0]['likes']);
        self::assertSame(3, $kept[1]['likes']);

        $many = array_fill(0, 80, ['text' => $long, 'likes' => 1]);
        self::assertCount(CommentFilter::MAX_KEPT, (new CommentFilter())->filter($many));
        self::assertCount(2, (new CommentFilter())->filter($many, 2));
    }

    public function testMissingLikesCountAsZeroAndNegativesAreClamped(): void
    {
        $text = 'the monsters tend to camp in front of the hiding spots which makes the second floor unplayable';
        $kept = (new CommentFilter())->filter([['text' => $text], ['text' => $text . '!', 'likes' => -5]]);
        self::assertSame([0, 0], array_column($kept, 'likes'));
    }
}
