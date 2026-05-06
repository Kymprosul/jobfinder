<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\TextNormalizer;

final class DeduplicationService
{
    public function merge(array $existingJobs, array $incomingJobs): array
    {
        $existingById = [];
        foreach ($existingJobs as $job) {
            $key = $this->resolveDedupeKey($job);
            $job['dedupe_key'] = $key;
            $job = $this->enrichSourceTrace($job);
            $job = $this->enrichPostedDateTrace($job);
            $existingById[$key] = $job;
        }

        $seenThisRun = [];
        $newJobKeys = [];
        $duplicatesDiscarded = 0;
        $now = (new \DateTimeImmutable('now'))->format(DATE_ATOM);

        foreach ($incomingJobs as $job) {
            $key = $this->resolveDedupeKey($job);
            $job['dedupe_key'] = $key;
            $job = $this->enrichSourceTrace($job);
            $job = $this->enrichPostedDateTrace($job);

            if (isset($seenThisRun[$key])) {
                $duplicatesDiscarded++;
                $existingById[$key] = $this->mergeJobData($existingById[$key], $job, $now);
                continue;
            }

            $seenThisRun[$key] = true;

            if (isset($existingById[$key])) {
                $existing = $existingById[$key];
                $job['first_seen_at'] = $existing['first_seen_at'] ?? $job['first_seen_at'];
                $job['last_seen_at'] = $now;
                $job['sent'] = (bool) ($existing['sent'] ?? false);
                $job['is_new'] = false;
                $existingById[$key] = $this->mergeJobData($existing, $job, $now);
                continue;
            }

            $job['first_seen_at'] = $now;
            $job['last_seen_at'] = $now;
            $job['is_new'] = true;
            $job['sent'] = false;
            $job = $this->enrichPostedDateTrace($job);
            $existingById[$key] = $job;
            $newJobKeys[$key] = true;
        }

        $newJobs = [];
        foreach (array_keys($newJobKeys) as $key) {
            if (isset($existingById[$key])) {
                $newJobs[] = $existingById[$key];
            }
        }

        return [
            'jobs' => array_values($existingById),
            'new_jobs' => $newJobs,
            'duplicates_discarded' => $duplicatesDiscarded,
        ];
    }

    private function resolveDedupeKey(array $job): string
    {
        $overlapKey = trim((string) ($job['overlap_key'] ?? ''));
        if ($overlapKey !== '') {
            return $overlapKey;
        }

        $existing = trim((string) ($job['dedupe_key'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $title = trim((string) ($job['normalized_title'] ?? TextNormalizer::normalize((string) ($job['title'] ?? ''))));
        $institution = trim((string) ($job['normalized_institution'] ?? TextNormalizer::normalize((string) ($job['institution'] ?? ''))));
        $location = trim((string) ($job['normalized_location'] ?? TextNormalizer::normalize((string) ($job['location'] ?? ''))));
        $postedDate = trim((string) ($job['posted_date'] ?? ''));
        $description = trim((string) ($job['description'] ?? ''));
        $descriptionSignature = $this->descriptionSignature($description);

        if ($institution !== '' && $descriptionSignature !== null) {
            return hash('sha256', implode('|', array_filter([$institution, $location, $descriptionSignature])));
        }

        $parts = array_filter([
            $title,
            $institution,
            $location,
            $postedDate !== '' ? $postedDate : null,
            $postedDate === '' ? $descriptionSignature : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return hash('sha256', implode('|', $parts));
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

    private function enrichSourceTrace(array $job): array
    {
        $sources = [];

        foreach (($job['raw_meta']['seen_sources'] ?? []) as $source) {
            $source = trim((string) $source);
            if ($source !== '') {
                $sources[$source] = $source;
            }
        }

        $currentSource = trim((string) ($job['source'] ?? ''));
        if ($currentSource !== '') {
            $sources[$currentSource] = $currentSource;
        }

        $job['raw_meta'] ??= [];
        $job['raw_meta']['seen_sources'] = array_values($sources);

        $urls = [];
        foreach (($job['raw_meta']['alternate_urls'] ?? []) as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                $urls[$url] = $url;
            }
        }

        $currentUrl = trim((string) ($job['url'] ?? ''));
        if ($currentUrl !== '') {
            $urls[$currentUrl] = $currentUrl;
        }

        $job['raw_meta']['alternate_urls'] = array_values($urls);

        return $job;
    }

    private function mergeJobData(array $primary, array $secondary, string $now): array
    {
        $merged = $primary;

        foreach (['institution', 'location', 'description', 'closing_date', 'category'] as $field) {
            if (trim((string) ($merged[$field] ?? '')) === '' && trim((string) ($secondary[$field] ?? '')) !== '') {
                $merged[$field] = $secondary[$field];
            }
        }

        if ((int) ($secondary['score'] ?? 0) > (int) ($merged['score'] ?? 0)) {
            $merged['score'] = (int) $secondary['score'];
        }

        $keywords = array_merge($primary['matched_keywords'] ?? [], $secondary['matched_keywords'] ?? []);
        $merged['matched_keywords'] = array_values(array_unique(array_filter(array_map(
            static fn ($keyword): string => trim((string) $keyword),
            $keywords
        ))));

        $merged['sent'] = (bool) (($primary['sent'] ?? false) || ($secondary['sent'] ?? false));
        $merged['is_new'] = (bool) ($primary['is_new'] ?? false);
        $merged['first_seen_at'] = $primary['first_seen_at'] ?? $secondary['first_seen_at'] ?? $now;
        $merged['last_seen_at'] = $now;
        $merged['raw_meta'] = array_merge($primary['raw_meta'] ?? [], $secondary['raw_meta'] ?? []);
        $merged['raw_meta']['seen_sources'] = array_values(array_unique(array_filter(array_merge(
            $primary['raw_meta']['seen_sources'] ?? [],
            $secondary['raw_meta']['seen_sources'] ?? []
        ))));
        $merged['raw_meta']['alternate_urls'] = array_values(array_unique(array_filter(array_merge(
            $primary['raw_meta']['alternate_urls'] ?? [],
            $secondary['raw_meta']['alternate_urls'] ?? []
        ))));
        $merged = $this->enrichSourceTrace($merged);
        $merged = $this->enrichPostedDateTrace($merged, $secondary);

        return $merged;
    }

    private function enrichPostedDateTrace(array $job, ?array $secondary = null): array
    {
        $history = $this->postedDateValuesFromJob($job);

        if ($secondary !== null) {
            $history = array_merge($history, $this->postedDateValuesFromJob($secondary));
        }

        $history = $this->normalizePostedDateHistory($history);

        if ($history !== []) {
            $job['posted_date_history'] = $history;
            $job['posted_date'] = $history[0];
        } else {
            $job['posted_date_history'] = [];
        }

        return $job;
    }

    private function postedDateValuesFromJob(array $job): array
    {
        $values = [];
        $postedDate = $this->normalizePostedDateValue($job['posted_date'] ?? null);
        if ($postedDate !== null) {
            $values[] = $postedDate;
        }

        if (is_array($job['posted_date_history'] ?? null)) {
            foreach ($job['posted_date_history'] as $date) {
                $normalized = $this->normalizePostedDateValue($date);
                if ($normalized !== null) {
                    $values[] = $normalized;
                }
            }
        }

        return $values;
    }

    private function normalizePostedDateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizePostedDateHistory(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $date = $this->normalizePostedDateValue($value);
            if ($date === null) {
                continue;
            }

            $normalized[$date] = $date;
        }

        $history = array_values($normalized);
        rsort($history, SORT_STRING);

        return $history;
    }
}
