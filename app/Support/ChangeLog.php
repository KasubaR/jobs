<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A record of who changed which field of which row, from what to what, and through which door. Written by the review
 * screen, the Employers screen and the CSV import, so a recognition claim or a mapping can always be traced.
 * Only real changes are logged: saving the same value again leaves no entry.
 */
final class ChangeLog
{
    /**
     * @param array<string, mixed> $after the fields as they now are (only these fields are compared)
     * @param object|array|null $before the row before the change, or null when the row is new
     */
    public static function diff(string $table, int $rowId, object|array|null $before, array $after, string $via): void
    {
        // a new row is one entry that says what it was created with, not one line per column
        if ($before === null) {
            $set = array_filter($after, fn ($v) => self::text($v) !== null);
            self::write($table, $rowId, 'created', null, null, mb_substr(json_encode(array_map(fn ($v) => self::text($v), $set), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1000), $via);

            return;
        }

        $before = (array) $before;
        foreach ($after as $field => $new) {
            $old = $before[$field] ?? null;
            if (self::text($old) !== self::text($new)) {
                self::write($table, $rowId, 'updated', $field, $old, $new, $via);
            }
        }
    }

    /** One value changed, when the caller already knows the old and the new. */
    public static function record(string $table, int $rowId, string $field, mixed $old, mixed $new, string $via, string $action = 'updated'): void
    {
        if (self::text($old) !== self::text($new)) {
            self::write($table, $rowId, $action, $field, $old, $new, $via);
        }
    }

    private static function write(string $table, int $rowId, string $action, ?string $field, mixed $old, mixed $new, string $via): void
    {
        DB::table('data_change_logs')->insert([
            'table_name' => $table, 'row_id' => $rowId, 'action' => $action, 'field' => $field,
            'old_value' => self::text($old), 'new_value' => self::text($new),
            'actor' => auth()->user()?->email ?? 'command', 'via' => $via, 'created_at' => now(),
        ]);
    }

    /** null and an empty string are the same "nothing"; booleans compare as 0 and 1. */
    private static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' => null,
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => (string) $value,
        };
    }
}
