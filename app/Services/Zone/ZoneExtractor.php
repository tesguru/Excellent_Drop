<?php

namespace App\Services\Zone;

use App\Services\ClickHouse\ClickHouseClient;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Extracts the sorted, unique list of second level labels and their
 * delegation nameservers from a gzipped CZDS zone file.
 *
 * The zone file is never read into PHP memory. The work is done by a shell
 * pipeline of `zcat | awk | sort`, driven through Symfony Process.
 */
class ZoneExtractor
{
    /**
     * awk program that keeps the owner name and target of NS records and
     * drops the trailing dot, the TLD, the zone apex and any name that has
     * more than one label in front of the TLD.
     */
    private const AWK_PROGRAM = <<<'AWK'
BEGIN { FS = "[ \t]+" }
{
    ns_index = 0
    for (i = 2; i <= NF; i++) {
        if (toupper($i) == "NS") { ns_index = i; break }
    }
    if (ns_index == 0) next
    if (ns_index >= NF) next

    owner = $1
    sub(/\.$/, "", owner)
    if (owner == "") next

    suffix = "." tld
    if (length(owner) <= length(suffix)) next
    if (tolower(substr(owner, length(owner) - length(suffix) + 1)) != suffix) next

    label = substr(owner, 1, length(owner) - length(suffix))
    if (label == "") next
    if (index(label, ".") > 0) next
    if (index(label, "\\") > 0) next

    target = $(ns_index + 1)
    sub(/\.$/, "", target)
    if (target == "") next
    if (index(target, "\\") > 0) next

    print tolower(label) "\t" tolower(target)
}
AWK;

    /**
     * Fold the sorted label/nameserver pairs into one TSV row per label.
     */
    private const AGGREGATE_PROGRAM = <<<'AWK'
BEGIN { FS = "\t" }
{
    if ($1 != prev) {
        if (prev != "") print prev "\t" ns
        prev = $1
        ns = $2
    } else if (index("," ns ",", "," $2 ",") == 0) {
        ns = ns "," $2
    }
}
END { if (prev != "") print prev "\t" ns }
AWK;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ClickHouseClient $clickhouse,
    ) {}

    /**
     * Run the extraction pipeline for one zone file.
     *
     * @return array{rows:int, bytes:int, path:string}
     */
    public function extract(string $gzPath, string $tld, string $outPath): array
    {
        if (! is_readable($gzPath)) {
            throw new \RuntimeException("Zone file is not readable: {$gzPath}");
        }

        $tmpDir = (string) $this->config['sort_tmp_dir'];

        $this->prepareTmpDir($tmpDir);

        // Compressed sort runs keep a .com extraction inside the 38G disk;
        // uncompressed temp needs ~10G on its own.
        $command = sprintf(
            'set -o pipefail; %s %s | LC_ALL=C awk -v tld=%s %s | LC_ALL=C sort -u -k1,1 -k2,2 --compress-program=gzip -T %s | LC_ALL=C awk %s > %s',
            $this->catBinary(),
            escapeshellarg($gzPath),
            escapeshellarg($tld),
            escapeshellarg(self::AWK_PROGRAM),
            escapeshellarg($tmpDir),
            escapeshellarg(self::AGGREGATE_PROGRAM),
            escapeshellarg($outPath),
        );

        $process = new Process(['/bin/bash', '-c', $command], null, null, null, 0);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                "Extraction failed for {$tld}: ".$process->getErrorOutput().' '.$process->getOutput(),
                $process->getExitCode() ?? 1,
            );
        }

        $rows = $this->countRows($outPath);

        if ($rows === 0) {
            throw new \RuntimeException(
                "Extraction for {$tld} produced no names. The zone file layout may have changed.",
            );
        }

        return [
            'rows' => $rows,
            'bytes' => (int) (filesize($outPath) ?: 0),
            'path' => $outPath,
        ];
    }

    /**
     * Verify that the zone file uses the expected owner/TTL/class/type/rdata
     * layout by inspecting the first records of the file.
     *
     * @return array{ok:bool, columns:int, sample:?string}
     */
    public function verifyLayout(string $gzPath, string $tld): array
    {
        $command = sprintf(
            'set -o pipefail; %s %s | head -n 500',
            $this->catBinary(),
            escapeshellarg($gzPath),
        );

        $process = new Process(['/bin/bash', '-c', $command], null, null, null, 120);
        $process->run();

        $lines = array_values(array_filter(
            explode("\n", $process->getOutput()),
            fn (string $line) => trim($line) !== '' && ! str_starts_with(trim($line), ';'),
        ));

        $sample = null;
        $columns = 0;

        foreach ($lines as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            foreach ($fields as $index => $field) {
                if (strtoupper($field) === 'NS') {
                    $columns = $index + 1; // owner + ttl + class + type
                    $sample = trim($line);
                    break 2;
                }
            }
        }

        $ok = $sample !== null;

        if (! $ok) {
            Log::warning('Zone file layout could not be verified', ['tld' => $tld, 'path' => $gzPath]);
        }

        return ['ok' => $ok, 'columns' => $columns, 'sample' => $sample];
    }

    private function prepareTmpDir(string $tmpDir): void
    {
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $free = @disk_free_space($tmpDir);

        if ($free !== false && $free < 30 * 1024 * 1024 * 1024) {
            Log::warning('Sort temp dir has less than 30GB free', [
                'dir' => $tmpDir,
                'free_gb' => round($free / 1024 / 1024 / 1024, 1),
            ]);
        }
    }

    private function countRows(string $path): int
    {
        $process = new Process(['/usr/bin/wc', '-l', $path], null, null, null, 600);
        $process->run();

        if (! $process->isSuccessful()) {
            return 0;
        }

        $parts = preg_split('/\s+/', trim($process->getOutput())) ?: [];

        return (int) ($parts[0] ?? 0);
    }

    private function catBinary(): string
    {
        return (string) ($this->config['cat_binary'] ?? 'gzip -dc');
    }
}
