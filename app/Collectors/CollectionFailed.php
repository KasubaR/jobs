<?php

namespace App\Collectors;

use RuntimeException;

/** A collection attempt failed in an expected way (bad HTTP status, unreadable response). */
class CollectionFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpErrors = 0, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
