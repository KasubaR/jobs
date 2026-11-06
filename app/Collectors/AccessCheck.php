<?php

namespace App\Collectors;

/** Result of Collector::check(). */
final class AccessCheck
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $httpStatus,
        public readonly int $durationMs,
        public readonly int $itemCount,
        public readonly string $message,
    ) {}
}
