<?php

declare(strict_types=1);

namespace App\Utils;

final class EnvLoader
{
    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === '') {
                continue;
            }

            $value = self::normalizeValue($value);

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv(sprintf('%s=%s', $key, $value));
        }
    }

    private static function normalizeValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            $value = substr($value, 1, -1);
        } else {
            $commentPosition = strpos($value, ' #');
            if ($commentPosition !== false) {
                $value = substr($value, 0, $commentPosition);
            }
        }

        return trim(str_replace(['\n', '\r', '\t'], ["\n", "\r", "\t"], $value));
    }
}
