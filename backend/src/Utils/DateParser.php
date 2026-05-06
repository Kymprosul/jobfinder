<?php

declare(strict_types=1);

namespace App\Utils;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class DateParser
{
    public static function parse(?string $value, ?DateTimeZone $timezone = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timezone ??= new DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'UTC');
        $normalized = preg_replace('/\s+/u', ' ', strtolower($value)) ?? strtolower($value);
        $normalized = str_replace(['posted', 'published', 'closing date:', 'closing time', 'apply by:', 'date posted:'], '', $normalized);
        $normalized = trim($normalized, " \t\n\r\0\x0B:-");

        if (preg_match('/(\d+)\s+(minute|hour|day|week|month)s?\s+ago/', $normalized, $matches) === 1) {
            $amount = (int) $matches[1];
            $unit = match ($matches[2]) {
                'minute' => 'PT' . $amount . 'M',
                'hour' => 'PT' . $amount . 'H',
                'day' => 'P' . $amount . 'D',
                'week' => 'P' . $amount . 'W',
                'month' => 'P' . $amount . 'M',
            };

            return (new DateTimeImmutable('now', $timezone))
                ->sub(new DateInterval($unit))
                ->format('Y-m-d');
        }

        if (preg_match('/^([a-z]{3,9}),?\s+(\d{1,2})$/i', $normalized, $matches) === 1) {
            $candidate = sprintf('%s %s %s', $matches[1], $matches[2], (new DateTimeImmutable('now', $timezone))->format('Y'));
            return self::create($candidate, $timezone);
        }

        $formats = [
            'Y-m-d',
            'Y/m/d',
            'F j, Y',
            'M j, Y',
            'F j Y',
            'M j Y',
            'j F Y',
            'j M Y',
            'm/d/Y',
            'd/m/Y',
        ];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d');
            }

            $date = DateTimeImmutable::createFromFormat($format, ucfirst($normalized), $timezone);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        return self::create($normalized, $timezone);
    }

    public static function isOlderThanDays(?string $date, int $days, ?DateTimeZone $timezone = null): bool
    {
        if ($date === null) {
            return false;
        }

        $timezone ??= new DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'UTC');
        $target = new DateTimeImmutable($date, $timezone);
        $limit = (new DateTimeImmutable('now', $timezone))->sub(new DateInterval('P' . $days . 'D'));

        return $target < $limit;
    }

    private static function create(string $value, DateTimeZone $timezone): ?string
    {
        try {
            return (new DateTimeImmutable($value, $timezone))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
