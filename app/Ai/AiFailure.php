<?php

namespace App\Ai;

use RuntimeException;

/** A request that did not complete. `retryable` says whether trying again could help; `retryAfter` is a wait the service asked for. */
class AiFailure extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly ?int $status = null, public readonly ?int $retryAfter = null, public readonly string $kind = 'error')
    {
        parent::__construct($message);
    }
}
