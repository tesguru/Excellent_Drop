<?php

namespace App\Services\PendingDelete;

use JsonException;

/**
 * Incremental parser for the PendingDelete.net JSON layout:
 *
 *   {"dataset":"...","columns":["domain","status",...],"rows":[["a.com",...],...],"count":N}
 *
 * The rows array is flat, but the parser still tracks nesting and strings so a
 * comma or bracket inside a registrar name cannot split a row. It accepts the
 * response in arbitrary chunks and never holds more than one row in memory.
 */
final class JsonRowStreamer
{
    private const METADATA_LIMIT = 1048576;

    private string $buffer = '';

    private bool $seekingRows = true;

    private bool $finishedRows = false;

    private int $depth = 0;

    private bool $inString = false;

    private bool $escaped = false;

    private string $row = '';

    /** @var array<int, string> */
    private array $columns = [];

    private string $tail = '';

    private int $rows = 0;

    private ?int $declaredCount = null;

    /**
     * Feed a chunk and return every row completed by it.
     *
     * @return array<int, mixed>
     */
    public function feed(string $chunk): array
    {
        if ($this->finishedRows) {
            $this->tail .= $chunk;
            $this->captureCount();

            return [];
        }

        $this->buffer .= $chunk;

        if ($this->seekingRows) {
            $this->seekRows();
        }

        if ($this->seekingRows) {
            return [];
        }

        return $this->scan();
    }

    /**
     * @return array{rows:int, count:?int, columns:array<int, string>}
     */
    public function finish(): array
    {
        if ($this->seekingRows) {
            throw new PendingDeleteException('The dataset does not contain a rows array.');
        }

        if (! $this->finishedRows) {
            throw new PendingDeleteException('The dataset ended before the rows array closed (partial download).');
        }

        $this->captureCount();

        if ($this->declaredCount !== null && $this->declaredCount !== $this->rows) {
            throw new PendingDeleteException(sprintf(
                'The dataset declared %d rows but %d were read.',
                $this->declaredCount,
                $this->rows,
            ));
        }

        return [
            'rows' => $this->rows,
            'count' => $this->declaredCount,
            'columns' => $this->columns,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    private function seekRows(): void
    {
        if (preg_match('/"rows"\s*:\s*\[/', $this->buffer, $match, PREG_OFFSET_CAPTURE) !== 1) {
            if (strlen($this->buffer) > self::METADATA_LIMIT) {
                throw new PendingDeleteException('The dataset metadata is malformed.');
            }

            return;
        }

        $start = (int) $match[0][1];
        $this->columns = $this->parseColumns(substr($this->buffer, 0, $start));

        $this->buffer = substr($this->buffer, $start + strlen((string) $match[0][0]));
        $this->seekingRows = false;
    }

    /**
     * @return array<int, string>
     */
    private function parseColumns(string $prefix): array
    {
        if (preg_match('/"columns"\s*:\s*(\[[^\]]*\])/', $prefix, $match) !== 1) {
            throw new PendingDeleteException('The dataset does not declare its columns.');
        }

        try {
            $columns = json_decode((string) $match[1], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PendingDeleteException('The dataset columns are not valid JSON.');
        }

        if (! is_array($columns) || $columns === []) {
            throw new PendingDeleteException('The dataset columns are empty.');
        }

        $columns = array_values(array_filter($columns, fn ($column) => is_string($column) && $column !== ''));

        if ($columns === []) {
            throw new PendingDeleteException('The dataset columns are empty.');
        }

        return $columns;
    }

    /**
     * @return array<int, mixed>
     */
    private function scan(): array
    {
        $emitted = [];
        $length = strlen($this->buffer);
        $index = 0;

        while ($index < $length) {
            $char = $this->buffer[$index];

            if ($this->depth === 0) {
                if ($char === ']') {
                    $this->finishedRows = true;
                    $this->tail = substr($this->buffer, $index + 1);
                    $this->buffer = '';
                    $this->captureCount();

                    return $emitted;
                }

                if ($char === '[') {
                    $this->depth = 1;
                    $this->row = '[';
                    $this->inString = false;
                    $this->escaped = false;
                    $index++;

                    continue;
                }

                if (ctype_space($char) || $char === ',') {
                    $index++;

                    continue;
                }

                throw new PendingDeleteException('The dataset rows array is malformed.');
            }

            $this->row .= $char;

            if ($this->inString) {
                if ($this->escaped) {
                    $this->escaped = false;
                } elseif ($char === '\\') {
                    $this->escaped = true;
                } elseif ($char === '"') {
                    $this->inString = false;
                }
            } else {
                if ($char === '"') {
                    $this->inString = true;
                } elseif ($char === '[') {
                    $this->depth++;
                } elseif ($char === ']') {
                    $this->depth--;

                    if ($this->depth === 0) {
                        $emitted[] = $this->decodeRow();
                        $this->row = '';
                    }
                }
            }

            $index++;
        }

        $this->buffer = '';

        return $emitted;
    }

    private function decodeRow(): mixed
    {
        try {
            $row = json_decode($this->row, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PendingDeleteException('A dataset row is not valid JSON.');
        }

        if (! is_array($row)) {
            throw new PendingDeleteException('A dataset row is not an array.');
        }

        $this->rows++;

        return $row;
    }

    private function captureCount(): void
    {
        if ($this->declaredCount !== null) {
            return;
        }

        if (preg_match('/"count"\s*:\s*(\d+)/', $this->tail, $match) === 1) {
            $this->declaredCount = (int) $match[1];
        }
    }
}
