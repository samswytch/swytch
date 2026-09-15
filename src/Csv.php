<?php

declare(strict_types=1);

/**
 * CSV export (BRIEF.md §10). Thirty lines of code and the insurance policy for
 * the whole project, so it stays dependency-free and obvious.
 */
final class Csv
{
    /**
     * @param array<int,string> $headers
     * @param array<int,array<int,string|int|null>> $rows
     */
    public static function build(array $headers, array $rows): string
    {
        $lines = [implode(',', array_map([self::class, 'cell'], $headers))];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map([self::class, 'cell'], $row));
        }

        // A byte order mark so Excel opens it as UTF-8 rather than mangling the
        // pound signs and curly quotes.
        return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Spreadsheets treat a leading =, +, - or @ as the start of a formula. The
     * log contains text the assistant wrote, so a cell is prefixed with an
     * apostrophe when it would otherwise be read as one.
     */
    private static function cell($value): string
    {
        if ($value === null) {
            return '""';
        }
        $text = (string) $value;
        if ($text !== '' && strpbrk($text[0], '=+-@') !== false) {
            $text = "'" . $text;
        }

        return '"' . str_replace('"', '""', $text) . '"';
    }

    public static function filename(string $stem): string
    {
        return $stem . '-' . Clock::fileStamp() . '.csv';
    }
}
