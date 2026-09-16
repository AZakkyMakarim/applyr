<?php

namespace App\Adapters\Exceptions;

use App\Enums\Platform;
use RuntimeException;
use Throwable;

/**
 * Base for the four ways an Adapter run can fail.
 */
abstract class AdapterException extends RuntimeException
{
    public function __construct(
        public readonly Platform $platform,
        string $message,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct("[{$platform->value}] {$message}", 0, $previous);
    }
}
