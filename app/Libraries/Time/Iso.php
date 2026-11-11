<?php

namespace App\Libraries\Time;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use UnexpectedValueException;

/**
 * Time formats of the API and the database. The API uses ISO 8601 UTC with milliseconds
 * (`2026-11-09T18:30:00.000Z`); the database stores `DATETIME(3)` in UTC. Precision is
 * milliseconds everywhere, so comparisons agree with the app.
 */
final class Iso
{
    private const API_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/';

    /** Parses an API or Google timestamp; null when the text is not ISO 8601 with a zone. */
    public static function parse(?string $text): ?DateTimeImmutable
    {
        if ($text === null || preg_match(self::API_PATTERN, $text) !== 1) {
            return null;
        }
        // PHP reads at most 6 fraction digits; Google may send 9.
        $text = preg_replace('/\.(\d{6})\d+/', '.$1', $text);

        try {
            $time = new DateTimeImmutable($text);
        } catch (Exception) {
            return null;
        }

        return self::toMillis($time->setTimezone(new DateTimeZone('UTC')));
    }

    public static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function formatOrNull(?DateTimeImmutable $time): ?string
    {
        return $time === null ? null : self::format($time);
    }

    public static function toDb(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    public static function toDbOrNull(?DateTimeImmutable $time): ?string
    {
        return $time === null ? null : self::toDb($time);
    }

    public static function fromDb(string $value): DateTimeImmutable
    {
        $utc  = new DateTimeZone('UTC');
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, $utc)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);
        if ($time === false) {
            throw new UnexpectedValueException('Unreadable database time.');
        }

        return $time;
    }

    public static function fromDbOrNull(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : self::fromDb($value);
    }

    private static function toMillis(DateTimeImmutable $time): DateTimeImmutable
    {
        $micros = (int) $time->format('u');

        return $time->setTime(
            (int) $time->format('H'),
            (int) $time->format('i'),
            (int) $time->format('s'),
            intdiv($micros, 1000) * 1000,
        );
    }
}
