<?php

declare(strict_types=1);

namespace App\Utils;

final class Str
{
    public static function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value);
        }

        return strtolower($value);
    }

    public static function slice(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? mb_substr($value, $start)
                : mb_substr($value, $start, $length);
        }

        return $length === null
            ? substr($value, $start)
            : substr($value, $start, $length);
    }
}
