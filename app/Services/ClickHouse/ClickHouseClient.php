<?php

namespace App\Services\ClickHouse;

use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

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

    private ?\CurlHandle $http = null;

    private bool $httpUnavailable = false;

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

        try {
            $process->run();
        } catch (ProcessExceptionInterface $exception) {
            throw $this->processFailure('clickhouse-client failed', $exception, $process);
        }

        if (! $process->isSuccessful()) {
            throw new ClickHouseException(
                $this->redact('clickhouse-client failed: '.$process->getErrorOutput().' | '.$process->getOutput()),
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
        if ($this->canUseHttp()) {
            $out = $this->httpQuery($sql, $params);
        } else {
            $out = $this->run($sql, $params, ['--format', 'JSONEachRow']);
        }

        return $this->decodeRows($out);
    }

    /**
     * Run a query that was built in PHP, sending the SQL through stdin so a
     * large batched IN list never has to fit in the process arguments.
     *
     * @param  array<string, int|string>  $settings
     * @return array<int, array<string, mixed>>
     */
    public function rowsFromSql(string $sql, array $settings = []): array
    {
        $process = new Process(
            array_merge(
                $this->binary(),
                $this->baseArgs,
                $this->settingArgs($settings),
                ['--format', 'JSONEachRow'],
            ),
            null,
            null,
            $sql,
            (float) $this->config['timeout'],
        );

        try {
            $process->run();
        } catch (ProcessExceptionInterface $exception) {
            throw $this->processFailure('clickhouse-client failed', $exception, $process);
        }

        if (! $process->isSuccessful()) {
            throw new ClickHouseException(
                $this->redact('clickhouse-client failed: '.$process->getErrorOutput()),
                $process->getExitCode() ?? 1,
            );
        }

        return $this->decodeRows($process->getOutput());
    }

    /**
     * Stream a result set row by row without ever loading all of it into one
     * PHP array.
     *
     * @param  callable(array<string, mixed>): void  $onRow
     * @param  array<string, int|string>  $settings
     */
    public function streamRows(string $sql, callable $onRow, array $settings = []): void
    {
        $process = new Process(
            array_merge(
                $this->binary(),
                $this->baseArgs,
                $this->settingArgs($settings),
                ['--format', 'JSONEachRow', '--query', $sql],
            ),
            null,
            null,
            null,
            // The caller consumes the stream at its own pace, so a wall-clock
            // process timeout would kill a healthy query while a chunk is
            // being processed.
            null,
        );

        $buffer = '';
        $errors = '';

        $emit = function (string $line) use ($onRow): void {
            $line = trim($line);

            if ($line === '') {
                return;
            }

            $row = json_decode($line, true);

            if (! is_array($row)) {
                throw new ClickHouseException('ClickHouse returned an unreadable row.');
            }

            $onRow($row);
        };

        try {
            $process->run(function (string $type, string $chunk) use (&$buffer, &$errors, $emit): void {
                if ($type === Process::ERR) {
                    $errors .= $chunk;

                    return;
                }

                $buffer .= $chunk;

                while (($position = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $position);
                    $buffer = substr($buffer, $position + 1);
                    $emit($line);
                }
            });

            $emit($buffer);
        } catch (ProcessExceptionInterface $exception) {
            $process->stop(0);

            throw $this->processFailure('clickhouse-client stream failed', $exception, $process);
        } catch (Throwable $exception) {
            $process->stop(0);

            throw $exception;
        }

        if (! $process->isSuccessful()) {
            throw new ClickHouseException(
                $this->redact('clickhouse-client stream failed: '.$errors),
                $process->getExitCode() ?? 1,
            );
        }
    }

    /**
     * Insert a batch of rows with JSONEachRow. Batches are built by the
     * caller so memory stays bounded while a run processes a large chunk.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function insertJsonEachRow(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $payload = '';

        foreach ($rows as $row) {
            $payload .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        }

        $process = new Process(
            array_merge($this->binary(), $this->baseArgs, ['--query', 'INSERT INTO '.$table.' FORMAT JSONEachRow']),
            null,
            null,
            $payload,
            (float) $this->config['timeout'],
        );

        try {
            $process->run();
        } catch (ProcessExceptionInterface $exception) {
            throw $this->processFailure('clickhouse-client insert failed', $exception, $process);
        }

        if (! $process->isSuccessful()) {
            throw new ClickHouseException(
                $this->redact('clickhouse-client insert failed: '.$process->getErrorOutput()),
                $process->getExitCode() ?? 1,
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeRows(string $output): array
    {
        $output = trim($output);

        if ($output === '') {
            return [];
        }

        $rows = [];

        foreach (explode("\n", $output) as $line) {
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
     * @param  array<string, int|string>  $settings
     * @return array<int, string>
     */
    private function settingArgs(array $settings): array
    {
        $args = [];

        foreach ($settings as $name => $value) {
            $args[] = '--'.$name.'='.$value;
        }

        return $args;
    }

    /**
     * Small lookups account for almost every query in a candidate run, so
     * they go through ClickHouse's HTTP interface. Reusing one curl handle
     * keeps the connection alive instead of paying ~200ms to start the
     * clickhouse-client binary for every row.
     *
     * @param  array<string, mixed>  $params
     */
    private function httpQuery(string $sql, array $params = []): string
    {
        $query = [];

        foreach ($params as $name => $value) {
            $query['param_'.$name] = $this->formatParam($value);
        }

        $url = sprintf(
            'http://%s:%d/?%s',
            $this->config['host'],
            (int) $this->config['http_port'],
            http_build_query(array_merge([
                'database' => $this->database(),
                'default_format' => 'JSONEachRow',
            ], $query), '', '&', PHP_QUERY_RFC3986),
        );

        $handle = $this->httpHandle();

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $sql,
            CURLOPT_HTTPHEADER => $this->httpHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => (int) $this->config['timeout'],
        ]);

        $body = curl_exec($handle);

        if ($body === false) {
            if (curl_errno($handle) === CURLE_COULDNT_CONNECT) {
                $this->httpUnavailable = true;

                return $this->run($sql, $params, ['--format', 'JSONEachRow']);
            }

            throw new ClickHouseException('ClickHouse HTTP request failed: '.curl_error($handle));
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($status !== 200) {
            throw new ClickHouseException('ClickHouse HTTP request failed ('.$status.'): '.(string) $body, $status);
        }

        return (string) $body;
    }

    private function canUseHttp(): bool
    {
        return ! $this->httpUnavailable
            && (int) ($this->config['http_port'] ?? 0) > 0
            && extension_loaded('curl');
    }

    private function httpHandle(): \CurlHandle
    {
        return $this->http ??= curl_init();
    }

    /**
     * @return array<int, string>
     */
    private function httpHeaders(): array
    {
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        if (! empty($this->config['user'])) {
            $headers[] = 'X-ClickHouse-User: '.$this->config['user'];
        }

        if (! empty($this->config['password'])) {
            $headers[] = 'X-ClickHouse-Key: '.$this->config['password'];
        }

        return $headers;
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

        try {
            $process->run();
        } catch (ProcessExceptionInterface $exception) {
            throw $this->processFailure('clickhouse-client insert failed', $exception, $process);
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful()) {
            throw new ClickHouseException(
                $this->redact('clickhouse-client insert failed: '.$process->getErrorOutput()),
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

        try {
            $process->run();
        } catch (ProcessExceptionInterface $exception) {
            throw $this->processFailure('clickhouse-client could not run '.$path, $exception, $process);
        }

        if (! $process->isSuccessful()) {
            throw new ClickHouseException(
                $this->redact('clickhouse-client could not run '.$path.': '.$process->getErrorOutput()),
                $process->getExitCode() ?? 1,
            );
        }
    }

    /**
     * Symfony's process exceptions include the full command line, which
     * contains the ClickHouse password. Never let it reach a log or a run row.
     */
    private function processFailure(string $context, Throwable $exception, Process $process): ClickHouseException
    {
        $message = $exception->getMessage();
        $stderr = trim($process->getErrorOutput());

        if ($stderr !== '') {
            $message .= ' | '.$stderr;
        }

        return new ClickHouseException(
            $this->redact($context.': '.$message),
            $process->getExitCode() ?? 1,
        );
    }

    private function redact(string $message): string
    {
        $password = (string) ($this->config['password'] ?? '');

        if ($password !== '') {
            $message = str_replace($password, 'REDACTED', $message);
        }

        return preg_replace(
            '/--password(?:=|\s+)(?:\'[^\']*\'|"[^"]*"|\S+)/',
            '--password REDACTED',
            $message,
        ) ?? $message;
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
