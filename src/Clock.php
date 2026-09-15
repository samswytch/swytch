<?php

declare(strict_types=1);

/**
 * Time, in one place.
 *
 * The server runs on UTC. Everything stored is UTC; everything a person reads
 * is Europe/London. Keeping the conversion here is what makes the clock change
 * on 25 October — which falls inside the cover period — a non-event: the daily
 * cap still resets at local midnight on the night the clocks go back, because
 * the boundary is computed in London time and converted, not assumed to be a
 * fixed offset.
 */
final class Clock
{
    public const ZONE = 'Europe/London';
    public const STORED = 'Y-m-d H:i:s';

    public static function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(self::STORED);
    }

    /** The UTC timestamp of the most recent midnight in London. */
    public static function startOfLondonDayUtc(): string
    {
        $london = new DateTimeImmutable('now', new DateTimeZone(self::ZONE));
        $midnight = $london->setTime(0, 0, 0);

        return $midnight->setTimezone(new DateTimeZone('UTC'))->format(self::STORED);
    }

    /** Parse a stored UTC timestamp back into a London-zoned value for display. */
    public static function toLondon(?string $storedUtc): ?DateTimeImmutable
    {
        if ($storedUtc === null || $storedUtc === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(
            self::STORED,
            $storedUtc,
            new DateTimeZone('UTC')
        );

        return $parsed === false ? null : $parsed->setTimezone(new DateTimeZone(self::ZONE));
    }

    /** "Mon 14 Sept, 23:17" — what the log screen shows. */
    public static function forHumans(?string $storedUtc): string
    {
        $when = self::toLondon($storedUtc);

        return $when === null ? '' : $when->format('D j M, H:i');
    }

    /** "2026-09-14 23:17" — what the CSV carries. */
    public static function forCsv(?string $storedUtc): string
    {
        $when = self::toLondon($storedUtc);

        return $when === null ? '' : $when->format('Y-m-d H:i');
    }

    /** "Monday, 14 September 2026" — what the model is told. */
    public static function todayForPrompt(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::ZONE)))->format('l, j F Y');
    }

    public static function fileStamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::ZONE)))->format('Y-m-d');
    }
}
