<?php

declare(strict_types=1);

namespace App\Utils;

final class TextNormalizer
{
    public static function normalize(?string $value): string
    {
        $value ??= '';
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        $value = preg_replace('/[^\p{L}\p{N}\s\-\.,\/&]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim(Str::lower($value));

        $equivalences = [
            'assoc professor' => 'associate professor',
            'asst professor' => 'assistant professor',
            'professor/a' => 'professor',
            'lecturer/senior lecturer' => 'lecturer senior lecturer',
        ];

        return str_replace(array_keys($equivalences), array_values($equivalences), $value);
    }

    public static function cleanUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return trim($url);
        }

        $path = $parts['path'] ?? '';
        return sprintf('%s://%s%s', $parts['scheme'], $parts['host'], rtrim($path, '/'));
    }
}
