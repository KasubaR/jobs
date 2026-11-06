<?php

namespace App\Collectors;

/** The one honest User-Agent every outgoing request uses. */
final class UserAgent
{
    public const TOKEN = 'ZamtrendsBot';

    public static function make(): string
    {
        return self::TOKEN.'/0.1 (private research; contact: '.config('job_intel.collector_contact').')';
    }
}
