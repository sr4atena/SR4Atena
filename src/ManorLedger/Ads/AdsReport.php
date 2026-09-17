<?php
/**
 * Reads a Roblox Ads Manager export into the campaign ledger data/ads.json.
 *
 * Spend is the one number the Analytics API never returns: it lives only in
 * the Ads Manager export, which the advertiser downloads by hand as a zip of
 * CSVs. Each zip holds the same table five times, once per attribution
 * window; only the "Default" one is a full account of the money, the others
 * (NewUsers, ReturningUsers, 7DResurrected, 30DResurrected) are subsets of the
 * same spend and would double-count if added. So this parser reads
 * `Roblox_Campaigns_Default_*.csv` and ignores the rest.
 *
 * The table is aggregated over the export window: a campaign is one row with
 * its total spend and its start/end dates, never a day-by-day series. Turning
 * that into a daily cost is the job of Analytics\AdsAnalysis; here we only
 * read, clean and date the rows.
 */
declare(strict_types=1);

namespace ManorLedger\Ads;

use RuntimeException;

final class AdsReport
{
    /** The only attribution window that accounts for the whole spend. */
    private const CAMPAIGNS_GLOB = 'Roblox_Campaigns_Default_*.csv';
    /** The export names itself with the window it covers: ..._2026-08-14_2026-09-14.csv */
    private const WINDOW_RE = '/_(\d{4}-\d{2}-\d{2})_(\d{4}-\d{2}-\d{2})\.csv$/';
    private const MONTHS = ['Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06',
        'Jul' => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12'];

    /**
     * @param string $path A .zip export, a directory of extracted CSVs, or the campaigns CSV itself.
     * @return array{source: string, window: array{from: ?string, to: ?string}, currency: string,
     *               campaigns: list<array<string, mixed>>}
     */
    public function read(string $path): array
    {
        $csv = $this->locate($path);
        $rows = $this->rows($csv['file']);
        $window = $this->window($csv['name']);
        $campaigns = [];
        foreach ($rows as $row) {
            $campaign = $this->campaign($row, $window);
            if ($campaign !== null) {
                $campaigns[] = $campaign;
            }
        }
        usort($campaigns, static fn (array $a, array $b) => [$a['from'], $a['name']] <=> [$b['from'], $b['name']]);

        return [
            'source'    => basename($path),
            'window'    => $window,
            'currency'  => 'USD',
            'campaigns' => $campaigns,
        ];
    }

    /**
     * The campaigns CSV inside a zip, a directory or a plain path.
     *
     * @return array{file: string, name: string} file = readable path, name = the name inside the export
     */
    private function locate(string $path): array
    {
        if (!file_exists($path)) {
            throw new RuntimeException("export not found: {$path}");
        }
        if (is_dir($path)) {
            $found = glob(rtrim($path, '/') . '/' . self::CAMPAIGNS_GLOB) ?: [];
            if ($found === []) {
                throw new RuntimeException('no ' . self::CAMPAIGNS_GLOB . " in {$path}");
            }

            return ['file' => $found[0], 'name' => basename($found[0])];
        }
        if (str_ends_with(strtolower($path), '.zip')) {
            return $this->fromZip($path);
        }

        return ['file' => $path, 'name' => basename($path)];
    }

    /**
     * Extracts the one CSV we need to a temp file. ext-zip is missing from
     * plain php:cli images, so `unzip` is the documented fallback: an export
     * must be readable wherever the import runs, workstation or VPS.
     *
     * @return array{file: string, name: string}
     */
    private function fromZip(string $zipPath): array
    {
        $dir = sys_get_temp_dir() . '/manor-ads-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create {$dir}");
        }
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException("cannot open {$zipPath}");
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if (fnmatch(self::CAMPAIGNS_GLOB, basename($name))) {
                    $copied = $dir . '/' . basename($name);
                    file_put_contents($copied, (string)$zip->getFromIndex($i));
                    $zip->close();

                    return ['file' => $copied, 'name' => basename($name)];
                }
            }
            $zip->close();
            throw new RuntimeException('no ' . self::CAMPAIGNS_GLOB . " in {$zipPath}");
        }
        exec(sprintf('unzip -o -j %s %s -d %s 2>/dev/null', escapeshellarg($zipPath), escapeshellarg(self::CAMPAIGNS_GLOB), escapeshellarg($dir)), $out, $code);
        $found = glob($dir . '/' . self::CAMPAIGNS_GLOB) ?: [];
        if ($code !== 0 || $found === []) {
            throw new RuntimeException("cannot read {$zipPath}: install ext-zip or unzip, or pass the extracted directory");
        }

        return ['file' => $found[0], 'name' => basename($found[0])];
    }

    /** @return list<array<string, string>> rows keyed by header name */
    private function rows(string $file): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException("cannot read {$file}");
        }
        $header = fgetcsv($handle);
        if ($header === false || $header === [null]) {
            fclose($handle);
            throw new RuntimeException("empty CSV: {$file}");
        }
        // Excel-flavoured exports start with a BOM; it would hide the first column.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
        }
        fclose($handle);

        return $rows;
    }

    /** @return array{from: ?string, to: ?string} */
    private function window(string $name): array
    {
        if (preg_match(self::WINDOW_RE, $name, $m) === 1) {
            return ['from' => $m[1], 'to' => $m[2]];
        }

        return ['from' => null, 'to' => null];
    }

    /**
     * @param array<string, string> $row
     * @param array{from: ?string, to: ?string} $window
     */
    private function campaign(array $row, array $window): ?array
    {
        $id = trim($row['Campaign ID'] ?? '');
        $name = trim($row['Campaign Name'] ?? '');
        if ($id === '' && $name === '') {
            return null;
        }
        $from = $this->date($row['Start Date'] ?? '');
        // An open-ended campaign is still running: it can only have spent up to
        // the last day the export covers.
        $to = $this->date($row['End Date'] ?? '') ?? $window['to'];
        if ($from !== null && $to !== null && $to < $from) {
            $to = $from;
        }

        return [
            'id'            => $id,
            'name'          => $name,
            'universeId'    => $this->int($row['Universe ID'] ?? '') ,
            'objective'     => trim($row['Objective'] ?? '') ?: null,
            'budgetType'    => trim($row['Budget Type'] ?? '') ?: null,
            'budget'        => $this->float($row['Budget'] ?? ''),
            'from'          => $from,
            'to'            => $to,
            'running'       => trim($row['End Date'] ?? '') === '',
            'spent'         => $this->float($row['Spent'] ?? '') ?? 0.0,
            'impressions'   => $this->int($row['Impressions'] ?? ''),
            'clicks'        => $this->int($row['Clicks'] ?? ''),
            'plays'         => $this->int($row['Plays'] ?? ''),
            'paymentMethod' => trim($row['Payment Method'] ?? '') ?: null,
        ];
    }

    /** "Aug 23, 2026" -> "2026-08-23"; anything unparseable -> null. */
    private function date(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw) === 1) {
            return $raw;
        }
        if (preg_match('/^([A-Za-z]{3})[a-z]*\s+(\d{1,2}),\s*(\d{4})$/', $raw, $m) === 1) {
            $month = self::MONTHS[ucfirst(strtolower($m[1]))] ?? null;
            if ($month !== null) {
                return sprintf('%04d-%s-%02d', (int)$m[3], $month, (int)$m[2]);
            }
        }

        return null;
    }

    /** "1,234" and "$1,234.56" are the export's own number formats. */
    private function float(string $raw): ?float
    {
        $clean = str_replace([',', '$', ' '], '', trim($raw));

        return is_numeric($clean) ? (float)$clean : null;
    }

    private function int(string $raw): ?int
    {
        $value = $this->float($raw);

        return $value === null ? null : (int)round($value);
    }
}
