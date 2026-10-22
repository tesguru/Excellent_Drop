<?php

namespace App\Services\ClickHouse;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper around the clickhouse-client binary.
 *
 * Everything goes through the binary rather than a PHP driver so that bulk
 * work (zone loads, diffs) never has to travel through the PHP process and
 * so that the same code path works on the production server.
 */
class ClickHouseClient
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var array<int, string> */
    private array $baseArgs;

    /** @var array<int, string>|null */
    private ?array $binary = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->baseArgs = [
            '--host', (string) $config['host'],
            '--port', (string) $config['port'],
        ];

        if (! empty($config['user'])) {
            $this->baseArgs[] = '--user';
            $this->baseArgs[] = (string) $config['user'];
        }

        if (! empty($config['password'])) {
            $this->baseArgs[] = '--password';
            $this->baseArgs[] = (string) $config['password'];
        }
    }

    /**
     * Resolve the clickhouse client binary.
     *
     * Production servers ship `clickhouse-client`, the single binary
     * distribution needs `clickhouse client`.
     *
     * @return array<int, string>
     */
    private function binary(): array
    {
        if ($this->binary !== null) {
            return $this->binary;
        }

        $finder = new ExecutableFinder;

        $configured = (string) ($this->config['client_bin'] ?? '');

        if ($configured !== '') {
            $path = $finder->find($configured);

            if ($path !== null) {
                return $this->binary = [$path];
            }
        }

        $path = $finder->find('clickhouse-client');

        if ($path !== null) {
            return $this->binary = [$path];
        }

        $path = $finder->find('clickhouse');

        if ($path !== null) {
            return $this->binary = [$path, 'client'];
        }

        throw new ClickHouseException('The clickhouse-client binary could not be found.');
    }

    /**
     * The database the tables live in.
     */
    public function database(): string
    {
        return (string) ($this->config['database'] ?? 'zones');
    }

    /**
     * Run a statement and return the raw stdout.
     *
     * @param  array<string, mixed>  $params
     */
    public function run(string $sql, array $params = [], array $extraArgs = []): string
    {
        $process = new Process(
            array_merge($this->binary(), $this->baseArgs, $this->paramArgs($params), $extraArgs, ['--query', $sql]),
            null,
            null,
            null,
            (float) $this->config['timeout'],
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new ClickHouseException(
                'clickhouse-client failed: '.$process->getErrorOutput().' | '.$process->getOutput(),
                $process->getExitCode() ?? 1,
            );
        }

        return $process->getOutput();
    }

    /**
     * Run a statement that returns rows.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $sql, array $params = []): array
    {
        $out = trim($this->run($sql, $params, ['--format', 'JSONEachRow']));

        if ($out === '') {
            return [];
        }

        $rows = [];
        foreach (explode("\n", $out) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    public function row(string $sql, array $params = []): ?array
    {
        return $this->rows($sql, $params)[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $row = $this->row($sql, $params);

        if ($row === null) {
            return null;
        }

        return array_values($row)[0] ?? null;
    }

    /**
     * Execute a statement without expecting output.
     *
     * @param  array<string, mixed>  $params
     */
    public function execute(string $sql, array $params = []): void
    {
        $this->run($sql, $params);
    }

    /**
     * Stream a TSV file into a table.
     *
     * The INSERT reads from `input()` and the file is streamed into the
     * process as a resource, so a multi-gigabyte file never passes through
     * the PHP process.
     */
    public function insertTsvFromFile(string $sql, string $filePath, array $params = [], ?float $timeout = null): void
    {
        if (! is_readable($filePath)) {
            throw new ClickHouseException("Cannot read input file: {$filePath}");
        }

        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new ClickHouseException("Cannot open input file: {$filePath}");
        }

        $process = new Process(
            array_merge($this->binary(), $this->baseArgs, $this->paramArgs($params), ['--query', $sql]),
            null,
            null,
            null,
            $timeout ?? (float) $this->config['timeout'],
        );

        $process->setInput($handle);
        $process->run();

        fclose($handle);

        if (! $process->isSuccessful()) {
            throw new ClickHouseException(
                'clickhouse-client insert failed: '.$process->getErrorOutput(),
                $process->getExitCode() ?? 1,
            );
        }
    }

    /**
     * Execute a file of SQL statements.
     */
    public function executeFile(string $path): void
    {
        if (! is_readable($path)) {
            throw new ClickHouseException("Cannot read SQL file: {$path}");
        }

        $process = new Process(
            array_merge($this->binary(), $this->baseArgs, ['--queries-file', $path]),
            null,
            null,
            null,
            (float) $this->config['timeout'],
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new ClickHouseException(
                'clickhouse-client could not run '.$path.': '.$process->getErrorOutput(),
                $process->getExitCode() ?? 1,
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function paramArgs(array $params): array
    {
        $args = [];

        foreach ($params as $name => $value) {
            $args[] = '--param_'.$name.'='.$this->formatParam($value);
        }

        return $args;
    }

    private function formatParam(mixed $value): string
    {
        if (is_array($value)) {
            $items = array_map(fn ($item) => "'".$this->escapeLiteral((string) $item)."'", $value);

            return '['.implode(',', $items).']';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return 'NULL';
        }

        return $this->escapeLiteral((string) $value);
    }

    private function escapeLiteral(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
