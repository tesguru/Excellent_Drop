<?php

namespace App\Services\Czds;

use RuntimeException;

class CzdsException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly bool $fatal = true,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
