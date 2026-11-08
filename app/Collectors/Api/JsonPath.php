<?php

namespace App\Collectors\Api;

/** Reads a value out of decoded JSON by a dot path such as data.jobs.0.title. Data only, never code. */
class JsonPath
{
    /** @return mixed null when any step is missing */
    public static function get(mixed $data, ?string $path): mixed
    {
        if ($path === null || $path === '') {
            return $data;
        }

        foreach (explode('.', $path) as $step) {
            if (! is_array($data) || ! array_key_exists($step, $data)) {
                return null;
            }
            $data = $data[$step];
        }

        return $data;
    }

    /** Text of a value: a number or string as is, a list of those joined by new lines; anything else is null. */
    public static function text(mixed $value): ?string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            $text = trim((string) $value);

            return $text === '' ? null : $text;
        }

        if (is_array($value) && array_is_list($value)) {
            $parts = array_filter(array_map(fn ($v) => is_array($v) ? null : self::text($v), $value));

            return $parts ? implode("\n", $parts) : null;
        }

        return null;
    }
}
