<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Ads;

use ManorLedger\Ads\AdsReport;
use ManorLedger\Tests\TempDirTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdsReportTest extends TestCase
{
    use TempDirTrait;

    private const HEADER = 'Campaign Name,Campaign ID,Universe ID,Start Date,End Date,Objective,Budget Type,Budget,'
        . 'Impressions,CPM,Clicks,CTR,CPC,Plays,Play Rate,CPP,Spent,Payment Method';

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function csv(string $body, string $name = 'Roblox_Campaigns_Default_Aggregated_2026-08-14_2026-09-14.csv'): string
    {
        $dir = $this->makeTempDir();
        $path = $dir . '/' . $name;
        file_put_contents($path, self::HEADER . "\n" . $body);

        return $path;
    }

    public function testReadsSpendDatesAndVolumes(): void
    {
        $path = $this->csv(
            'Manor,abc,10674300622,"Aug 23, 2026","Aug 30, 2026",Maximize Plays,Daily,16.00,"505,447",0.20,"91,400",18.08,0.001,"111,865",22.13,0.000,103.89,Ad Credit' . "\n"
        );
        $report = (new AdsReport())->read($path);

        self::assertSame(['from' => '2026-08-14', 'to' => '2026-09-14'], $report['window']);
        self::assertSame('USD', $report['currency']);
        self::assertCount(1, $report['campaigns']);
        $campaign = $report['campaigns'][0];
        self::assertSame('Manor', $campaign['name']);
        self::assertSame('2026-08-23', $campaign['from']);
        self::assertSame('2026-08-30', $campaign['to']);
        self::assertFalse($campaign['running']);
        self::assertSame(103.89, $campaign['spent']);
        // Thousands separators are the export's own formatting, not data.
        self::assertSame(505447, $campaign['impressions']);
        self::assertSame(91400, $campaign['clicks']);
        self::assertSame(111865, $campaign['plays']);
        self::assertSame(16.0, $campaign['budget']);
        self::assertSame('Maximize Plays', $campaign['objective']);
    }

    public function testOpenEndedCampaignEndsWithTheExportWindow(): void
    {
        $path = $this->csv(
            'Still running,xyz,10674300622,"Sep 11, 2026",,Maximize Plays,Daily,35.00,"11,440",0.17,"1,727",15.10,0.001,"1,218",10.65,0.001,72.91,Ad Credit' . "\n"
        );
        $campaign = (new AdsReport())->read($path)['campaigns'][0];

        self::assertTrue($campaign['running']);
        self::assertSame('2026-09-14', $campaign['to'], 'an open campaign can only have spent up to the last day covered');
    }

    public function testSortsByStartDateAndSkipsNamelessRows(): void
    {
        $path = $this->csv(
            'Second,b,1,"Sep 11, 2026","Sep 12, 2026",Earnings,Daily,25.00,10,0.4,2,20,0.01,1,10,0.1,5.00,Ad Credit' . "\n"
            . ',,,,,,,,,,,,,,,,,' . "\n"
            . 'First,a,1,"Aug 23, 2026","Aug 24, 2026",Earnings,Daily,25.00,10,0.4,2,20,0.01,1,10,0.1,3.00,Ad Credit' . "\n"
        );
        $campaigns = (new AdsReport())->read($path)['campaigns'];

        self::assertSame(['First', 'Second'], array_column($campaigns, 'name'));
    }

    public function testMissingNumbersStayNullInsteadOfZero(): void
    {
        $path = $this->csv(
            'No clicks,a,,"Aug 23, 2026",,Unspecified,Daily,16.00,"63,212",0.28,,,,,,,17.89,Ad Credit' . "\n"
        );
        $campaign = (new AdsReport())->read($path)['campaigns'][0];

        self::assertNull($campaign['clicks']);
        self::assertNull($campaign['plays']);
        self::assertNull($campaign['universeId']);
        self::assertSame(17.89, $campaign['spent']);
    }

    public function testReadsADirectoryOfExtractedFiles(): void
    {
        $path = $this->csv('Manor,a,1,"Aug 23, 2026","Aug 24, 2026",Earnings,Daily,25.00,10,0.4,2,20,0.01,1,10,0.1,3.00,Ad Credit' . "\n");
        $report = (new AdsReport())->read(dirname($path));

        self::assertCount(1, $report['campaigns']);
    }

    public function testUnknownPathFails(): void
    {
        $this->expectException(RuntimeException::class);
        (new AdsReport())->read($this->makeTempDir() . '/nope.zip');
    }
}
