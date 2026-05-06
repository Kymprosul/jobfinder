<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\DateParser;
use App\Utils\TextNormalizer;

final class JobFilterService
{
    public function filter(array $jobs, array $config): array
    {
        $evaluatedJobs = $this->evaluateJobs($jobs, $config);
        $accepted = [];
        $discarded = [
            'irrelevant' => 0,
            'too_old' => 0,
            'missing_date' => 0,
        ];

        foreach ($evaluatedJobs as $evaluated) {
            if ($evaluated['accepted']) {
                $accepted[] = $evaluated['job'];
                continue;
            }

            $reason = $evaluated['reason'] ?? 'irrelevant';
            $discarded[$reason] = ($discarded[$reason] ?? 0) + 1;
        }

        return [
            'accepted' => $accepted,
            'discarded' => $discarded,
            'evaluated' => $evaluatedJobs,
        ];
    }

    public function evaluateJobs(array $jobs, array $config): array
    {
        return array_map(fn (array $job): array => $this->evaluate($job, $config), $jobs);
    }

    private function evaluate(array $job, array $config): array
    {
        $searches = $this->configuredSearches($config);
        $title = TextNormalizer::normalize($job['title'] ?? '');
        $description = TextNormalizer::normalize($job['description'] ?? '');
        $institution = TextNormalizer::normalize($job['institution'] ?? '');
        $haystack = implode(' ', [$title, $description, $institution]);

        $bestCandidate = null;
        $bestAccepted = null;

        foreach ($searches as $search) {
            $candidate = $this->evaluateAgainstSearch($job, $search, $title, $description, $institution, $haystack);

            if ($bestCandidate === null || $candidate['score'] > $bestCandidate['score']) {
                $bestCandidate = $candidate;
            }

            if ($candidate['accepted'] && ($bestAccepted === null || $candidate['score'] > $bestAccepted['score'])) {
                $bestAccepted = $candidate;
            }
        }

        $winner = $bestAccepted ?? $bestCandidate;
        $job['category'] = $winner['search_key'] ?? null;
        $job['score'] = (int) ($winner['score'] ?? 0);
        $job['matched_keywords'] = array_values(array_unique($winner['matched_keywords'] ?? []));

        if ($bestAccepted !== null) {
            return [
                'accepted' => true,
                'job' => $job,
            ];
        }

        return [
            'accepted' => false,
            'job' => $job,
            'reason' => $winner['reason'] ?? 'irrelevant',
        ];
    }

    private function evaluateAgainstSearch(
        array $job,
        array $search,
        string $title,
        string $description,
        string $institution,
        string $haystack
    ): array {
        $filters = is_array($search['filters'] ?? null) ? $search['filters'] : [];
        $threshold = (int) ($filters['score_threshold'] ?? 5);
        $maxAgeDays = (int) ($filters['max_age_days'] ?? 90);
        $discardWithoutDate = (bool) ($filters['discard_without_posted_date'] ?? true);
        $searchKey = (string) ($search['key'] ?? '');
        $requirements = TextNormalizer::normalize((string) (($job['raw_meta']['requirements'] ?? '')));

        $score = 0;
        $matchedKeywords = [];

        foreach ($search['keywords'] ?? [] as $keyword) {
            $keywordNormalized = TextNormalizer::normalize($keyword);

            if ($keywordNormalized !== '' && str_contains($title, $keywordNormalized)) {
                $score += 5;
                $matchedKeywords[] = $keyword;
            }

            if ($keywordNormalized !== '' && str_contains($description, $keywordNormalized)) {
                $score += 3;
                $matchedKeywords[] = $keyword;
            }

            if ($keywordNormalized !== '' && str_contains($requirements, $keywordNormalized)) {
                $score += 2;
                $matchedKeywords[] = $keyword;
            }
        }

        $score += $this->applyCompositeSignals(
            $job,
            $title,
            $description,
            $institution,
            $searchKey,
            $matchedKeywords
        );

        if ($searchKey === 'business' && $score > 0) {
            $teachingContextSignals = [
                'teacher',
                'lecturer',
                'instructor',
                'professor',
                'faculty',
                'teaching fellow',
                'guest lecturer',
                'university',
                'college',
                'school of business',
                'international school',
                'high school',
                'secondary school',
            ];
            $teachingContext = TextNormalizer::normalize($title . ' ' . $description . ' ' . $institution);

            if (!$this->containsAny($teachingContext, $teachingContextSignals)) {
                $score = 0;
                $matchedKeywords = [];
            }
        }

        if ($score > 0) {
            $score += $this->applyPositiveSupportSignals(
                $title,
                $description,
                $institution,
                $haystack,
                $search['positive_support'] ?? [],
                $matchedKeywords
            );
        }

        foreach ($search['excluded'] ?? [] as $excluded) {
            $excludedNormalized = TextNormalizer::normalize($excluded);
            if ($excludedNormalized !== '' && $this->containsTerm($title, $excludedNormalized)) {
                $score -= 5;
            }

            if ($excludedNormalized !== '' && ($this->containsTerm($description, $excludedNormalized) || $this->containsTerm($institution, $excludedNormalized))) {
                $score -= 3;
            }

            if ($excludedNormalized !== '' && $this->containsTerm($requirements, $excludedNormalized)) {
                $score -= 2;
            }
        }

        if ($score > 0) {
            $tags = is_array($job['tags'] ?? null) ? $job['tags'] : [];
            $normalizedTags = array_map(static fn ($tag): string => TextNormalizer::normalize((string) $tag), $tags);

            if ($searchKey === 'spanish' && in_array('native_required', $normalizedTags, true)) {
                $score += 3;
            }

            if (in_array('phd_required', $normalizedTags, true)) {
                $score += 2;
            }
        }

        $result = [
            'search_key' => $search['key'] ?? null,
            'score' => $score,
            'matched_keywords' => array_values(array_unique($matchedKeywords)),
            'accepted' => false,
            'reason' => 'irrelevant',
        ];

        if ($score <= 0 || $score < $threshold) {
            return $result;
        }

        $postedDate = $job['posted_date'] ?? null;
        if ($postedDate === null || trim((string) $postedDate) === '') {
            if ($discardWithoutDate) {
                $result['reason'] = 'missing_date';
                return $result;
            }
        } elseif (DateParser::isOlderThanDays($postedDate, $maxAgeDays)) {
            $result['reason'] = 'too_old';
            return $result;
        }

        $result['accepted'] = true;

        return $result;
    }

    private function applyPositiveSupportSignals(
        string $title,
        string $description,
        string $institution,
        string $haystack,
        array $positiveSupport,
        array &$matchedKeywords
    ): int {
        $score = 0;

        foreach ($positiveSupport as $term) {
            $normalized = TextNormalizer::normalize($term);
            if ($normalized === '') {
                continue;
            }

            if ($this->containsTerm($title, $normalized)) {
                $score += 2;
                $matchedKeywords[] = $term;
                continue;
            }

            if ($this->containsTerm($description, $normalized) || $this->containsTerm($institution, $normalized) || str_contains($haystack, $normalized)) {
                $score += 1;
                $matchedKeywords[] = $term;
            }
        }

        return $score;
    }

    private function applyCompositeSignals(
        array $job,
        string $title,
        string $description,
        string $institution,
        string $searchKey,
        array &$matchedKeywords
    ): int {
        $score = 0;
        $academicRoles = [
            'teacher',
            'lecturer',
            'instructor',
            'professor',
            'faculty',
            'teaching fellow',
            'guest lecturer',
        ];

        if ($searchKey === 'spanish') {
            $spanishSignals = ['spanish', 'hispanic', 'iberian', 'romance language'];

            if ($this->containsAny($title, $spanishSignals) && $this->containsAny($title . ' ' . $description, $academicRoles)) {
                $score += 5;
                $matchedKeywords[] = 'spanish_teaching_pattern';
            } elseif ($this->containsAny($description, $spanishSignals) && $this->containsAny($description, $academicRoles)) {
                $score += 3;
                $matchedKeywords[] = 'spanish_teaching_pattern';
            }
        }

        if ($searchKey === 'business') {
            $businessSignals = [
                'international business',
                'international business teacher',
                'international business lecturer',
                'international business professor',
                'business teacher',
                'business lecturer',
                'business professor',
                'economics teacher',
                'economics lecturer',
                'economics professor',
                'school of business',
                'business faculty',
            ];
            $titleWithInstitution = implode(' ', [$title, $institution]);

            if ($this->containsAny($title, $businessSignals) && $this->containsAny($titleWithInstitution, $academicRoles)) {
                $score += 5;
                $matchedKeywords[] = 'business_teaching_pattern';
            }

            $strongSignals = [
                'international business teacher',
                'international business lecturer',
                'international business professor',
                'lecturer in international business',
                'professor of international business',
                'assistant professor in international business',
                'associate professor in international business',
                'faculty international business',
                'teaching fellow international business',
            ];

            if ($this->containsAny($title, $strongSignals)) {
                $score += 4;
                $matchedKeywords[] = 'strong_business_title';
            }
        }

        $source = TextNormalizer::normalize((string) ($job['source'] ?? ''));
        $jobCategory = TextNormalizer::normalize((string) (($job['raw_meta']['job_category'] ?? '')));

        if ($source === 'higheredjobs' && $jobCategory !== '') {
            if ($searchKey === 'business') {
                $businessCategories = [
                    'international business',
                    'other business faculty',
                    'finance',
                    'accounting',
                    'management',
                    'economics',
                    'marketing and sales',
                    'entrepreneurship',
                ];

                if ($this->matchesDescriptor($jobCategory, $businessCategories)) {
                    $score += 6;
                    $matchedKeywords[] = 'higheredjobs_business_category';
                }
            }

            if ($searchKey === 'spanish') {
                $spanishCategories = [
                    'foreign languages and literatures',
                    'spanish',
                    'hispanic studies',
                    'iberian studies',
                    'romance languages',
                ];

                if ($this->matchesDescriptor($jobCategory, $spanishCategories)) {
                    $score += 6;
                    $matchedKeywords[] = 'higheredjobs_spanish_category';
                }
            }
        }

        return $score;
    }

    private function configuredSearches(array $config): array
    {
        $searches = $config['searches'] ?? [];
        if (is_array($searches) && $searches !== []) {
            $normalized = array_values(array_filter(array_map(static function ($search) use ($config): ?array {
                if (!is_array($search)) {
                    return null;
                }

                $key = trim((string) ($search['key'] ?? ''));
                if ($key === '') {
                    return null;
                }

                return [
                    'key' => $key,
                    'label' => trim((string) ($search['label'] ?? '')),
                    'keywords' => (function () use ($search, $key, $config): array {
                        $inline = is_array($search['keywords'] ?? null) ? $search['keywords'] : [];
                        if ($inline !== []) {
                            return $inline;
                        }

                        $fallback = $config['keywords'][$key] ?? [];

                        return is_array($fallback) ? $fallback : [];
                    })(),
                    'positive_support' => (function () use ($search, $config): array {
                        $inline = is_array($search['positive_support'] ?? null) ? $search['positive_support'] : [];
                        if ($inline !== []) {
                            return $inline;
                        }

                        $fallback = $config['keywords']['positive_support'] ?? [];

                        return is_array($fallback) ? $fallback : [];
                    })(),
                    'excluded' => (function () use ($search, $config): array {
                        $inline = is_array($search['excluded'] ?? null) ? $search['excluded'] : [];
                        if ($inline !== []) {
                            return $inline;
                        }

                        $fallback = $config['keywords']['excluded'] ?? [];

                        return is_array($fallback) ? $fallback : [];
                    })(),
                    'filters' => is_array($search['filters'] ?? null) ? $search['filters'] : [],
                ];
            }, $searches)));

            if ($normalized !== []) {
                return $normalized;
            }
        }

        $legacySearches = [];
        foreach (array_keys($config['keywords'] ?? []) as $key) {
            if (in_array($key, ['positive_support', 'excluded'], true)) {
                continue;
            }

            $legacySearches[] = [
                'key' => $key,
                'label' => ucwords(str_replace('_', ' ', $key)),
                'keywords' => is_array($config['keywords'][$key] ?? null) ? $config['keywords'][$key] : [],
                'positive_support' => is_array($config['keywords']['positive_support'] ?? null) ? $config['keywords']['positive_support'] : [],
                'excluded' => is_array($config['keywords']['excluded'] ?? null) ? $config['keywords']['excluded'] : [],
                'filters' => is_array($config['filters'] ?? null) ? $config['filters'] : [],
            ];
        }

        return $legacySearches !== [] ? $legacySearches : [
            [
                'key' => 'spanish',
                'label' => 'Spanish',
                'keywords' => [],
                'positive_support' => [],
                'excluded' => [],
                'filters' => [],
            ],
        ];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = TextNormalizer::normalize($needle);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function containsTerm(string $haystack, string $needle): bool
    {
        $haystack = ' ' . TextNormalizer::normalize($haystack) . ' ';
        $needle = trim(TextNormalizer::normalize($needle));

        if ($needle === '') {
            return false;
        }

        if (str_contains($needle, ' ')) {
            return str_contains($haystack, ' ' . $needle . ' ');
        }

        return preg_match('/(?<!\p{L})' . preg_quote($needle, '/') . '(?!\p{L})/u', $haystack) === 1;
    }

    private function matchesDescriptor(string $value, array $candidates): bool
    {
        $value = TextNormalizer::normalize($value);
        if ($value === '') {
            return false;
        }

        foreach ($candidates as $candidate) {
            if ($value === TextNormalizer::normalize($candidate)) {
                return true;
            }
        }

        return false;
    }
}
