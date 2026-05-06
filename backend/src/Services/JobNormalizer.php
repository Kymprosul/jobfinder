<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\TextNormalizer;

final class JobNormalizer
{
    public function normalize(array $rawJob): array
    {
        $source = TextNormalizer::normalize($rawJob['source'] ?? '');
        $title = trim((string) ($rawJob['title'] ?? ''));
        $institution = trim((string) ($rawJob['institution'] ?? ''));
        $location = trim((string) ($rawJob['location'] ?? ''));
        $url = trim((string) ($rawJob['url'] ?? ''));

        $normalizedTitle = TextNormalizer::normalize($title);
        $normalizedInstitution = TextNormalizer::normalize($institution);
        $normalizedLocation = TextNormalizer::normalize($location);
        $cleanUrl = TextNormalizer::cleanUrl($url);
        $postedDate = trim((string) ($rawJob['posted_date'] ?? ''));
        $descriptionSignature = $this->descriptionSignature(trim((string) ($rawJob['description'] ?? '')));
        $publisherOverlapFingerprint = array_filter([
            $normalizedInstitution,
            $normalizedLocation,
            $descriptionSignature,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $fingerprint = implode('|', array_filter([
            $source,
            $normalizedTitle,
            $normalizedInstitution,
            $normalizedLocation,
            $cleanUrl,
        ], static fn (?string $value): bool => $value !== null && $value !== ''));

        $dedupeFingerprint = array_filter([
            $normalizedTitle,
            $normalizedInstitution,
            $normalizedLocation,
            $postedDate !== '' ? $postedDate : null,
            $postedDate === '' ? $descriptionSignature : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $job = [
            'id' => hash('sha256', $fingerprint),
            'dedupe_key' => hash('sha256', implode('|', $dedupeFingerprint)),
            'overlap_key' => $publisherOverlapFingerprint === []
                ? null
                : hash('sha256', implode('|', $publisherOverlapFingerprint)),
            'source' => $source,
            'title' => $title,
            'institution' => $institution,
            'location' => $location,
            'url' => $url,
            'description' => trim((string) ($rawJob['description'] ?? '')),
            'posted_date' => $rawJob['posted_date'] ?? null,
            'closing_date' => $rawJob['closing_date'] ?? null,
            'category' => $rawJob['category'] ?? null,
            'matched_keywords' => $rawJob['matched_keywords'] ?? [],
            'score' => (int) ($rawJob['score'] ?? 0),
            'is_new' => (bool) ($rawJob['is_new'] ?? true),
            'sent' => (bool) ($rawJob['sent'] ?? false),
            'first_seen_at' => $rawJob['first_seen_at'] ?? (new \DateTimeImmutable('now'))->format(DATE_ATOM),
            'last_seen_at' => $rawJob['last_seen_at'] ?? (new \DateTimeImmutable('now'))->format(DATE_ATOM),
            'raw_meta' => $rawJob['raw_meta'] ?? [],
            'normalized_title' => $normalizedTitle,
            'normalized_institution' => $normalizedInstitution,
            'normalized_location' => $normalizedLocation,
            'clean_url' => $cleanUrl,
        ];

        $job['tags'] = $this->extractTags($job);

        return $job;
    }

    private function descriptionSignature(string $description): ?string
    {
        $normalized = TextNormalizer::normalize($description);
        if ($normalized === '') {
            return null;
        }

        $words = preg_split('/\s+/u', $normalized) ?: [];
        $words = array_slice(array_values(array_filter($words, static fn (string $word): bool => $word !== '')), 0, 24);

        return $words === [] ? null : implode(' ', $words);
    }

    private function extractTags(array $job): array
    {
        $tags = [];

        $title = (string) ($job['title'] ?? '');
        $description = (string) ($job['description'] ?? '');
        $text = strtolower($title . ' ' . $description);

        if (
            preg_match('/\bnative\s+english\b/', $text)
            || preg_match('/\bnative[\s-]?speaker\b/', $text)
            || preg_match('/\bnative\s+english\s+speaker\b/', $text)
            || preg_match('/\benglish\s+native\s+speaker/i', $text)
            || preg_match('/\benglish\s+native\b/i', $text)
            || preg_match('/\bmother\s+tongue\s+english\b/', $text)
            || preg_match('/\bfirst\s+language\s+english\b/', $text)
        ) {
            $tags[] = 'native_required';
        }

        $location = trim((string) ($job['location'] ?? ''));
        if ($location !== '') {
            $segments = array_values(array_filter(array_map('trim', explode(',', $location)), static fn (string $segment): bool => $segment !== ''));
            foreach ($segments as $segment) {
                if (strtolower($segment) === 'china') {
                    continue;
                }

                $city = strtolower($segment);
                $city = preg_replace('/\s+/u', '_', $city);
                $city = preg_replace('/[^a-z0-9_]/', '', $city ?? '');
                if ($city !== '') {
                    $tags[] = 'city:' . $city;
                }
                break;
            }
        }

        $rawMeta = is_array($job['raw_meta'] ?? null) ? $job['raw_meta'] : [];

        $requirements = (string) ($rawMeta['requirements'] ?? '');
        $phdText = strtolower($title . ' ' . $description . ' ' . $requirements);
        if (
            preg_match('/\bph\.?d\.?\s+required\b/', $phdText)
            || preg_match('/\bph\.?d\.?\s+preferred\b/', $phdText)
            || preg_match('/\bdoctorate\s+required\b/', $phdText)
            || preg_match('/\bdoctoral\s+degree\b/', $phdText)
            || preg_match('/\bmust\s+have\s+a?\s*ph\.?d\.?\b/', $phdText)
            || preg_match('/\bmust\s+hold\s+a\s+ph\.?d\.?\b/', $phdText)
        ) {
            $tags[] = 'phd_required';
        }

        return array_values(array_unique($tags));
    }
}
