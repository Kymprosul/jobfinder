<?php

declare(strict_types=1);

namespace App\Services;

use App\Storage\StorageInterface;
use App\Utils\Str;

final class ConfigService
{
    private const RESERVED_SEARCH_KEYS = ['positive_support', 'excluded'];

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly array $env
    ) {
    }

    public function get(): array
    {
        $stored = $this->storage->load('config', []);

        return $this->normalizeConfig($this->mergeRecursiveDistinct($this->defaults(), $stored));
    }

    public function getPublicConfig(): array
    {
        $config = $this->get();
        $config['meta'] = [
            'smtp_configured' => $this->isSmtpConfigured(),
            'app_timezone' => $this->env['APP_TIMEZONE'] ?? 'UTC',
            'app_base_url' => $this->sanitizeHttpUrl($this->env['APP_BASE_URL'] ?? 'http://localhost:5173'),
        ];

        return $config;
    }

    public function save(array $input): array
    {
        $current = $this->get();
        $currentSearchKeys = $this->extractSearchKeys($current);
        $merged = $current;

        if (isset($input['email']) && is_array($input['email'])) {
            $merged['email'] = $this->sanitizeFixedShape($input['email'], $current['email']);
        }

        if (isset($input['sources']) && is_array($input['sources'])) {
            $merged['sources'] = $this->sanitizeFixedShape($input['sources'], $current['sources']);
        }

        if (isset($input['searches']) && is_array($input['searches'])) {
            $merged['searches'] = $input['searches'];
        }

        if (isset($input['filters']) && is_array($input['filters'])) {
            $merged['filters'] = $this->sanitizeFixedShape($input['filters'], $current['filters']);
        }

        if (isset($input['keywords']) && is_array($input['keywords'])) {
            $merged['keywords'] = $input['keywords'];
        }

        $merged = $this->normalizeConfig($merged);
        $nextSearchKeys = $this->extractSearchKeys($merged);
        $removedSearchKeys = array_values(array_diff($currentSearchKeys, $nextSearchKeys));

        if ($removedSearchKeys !== []) {
            $this->purgeSearchHistory($removedSearchKeys);
        }

        $this->storage->save('config', $merged);

        return $merged;
    }

    public function isSmtpConfigured(): bool
    {
        $required = [
            'SMTP_HOST' => $this->env['SMTP_HOST'] ?? '',
            'SMTP_PORT' => $this->env['SMTP_PORT'] ?? '',
            'SMTP_USERNAME' => $this->env['SMTP_USERNAME'] ?? '',
            'SMTP_PASSWORD' => $this->env['SMTP_PASSWORD'] ?? '',
            'SMTP_FROM_EMAIL' => $this->env['SMTP_FROM_EMAIL'] ?? '',
        ];

        foreach ($required as $value) {
            if (trim((string) $value) === '') {
                return false;
            }
        }

        $placeholders = [
            'smtp.example.com',
            'usuario@example.com',
            'secret',
            'no-reply@example.com',
        ];

        foreach ($required as $value) {
            if (in_array(Str::lower(trim((string) $value)), $placeholders, true)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeConfig(array $config): array
    {
        $searches = $this->normalizeSearches($config);
        $legacyKeywords = is_array($config['keywords'] ?? null) ? $config['keywords'] : [];
        $legacyFilters = is_array($config['filters'] ?? null) ? $config['filters'] : [];

        $config['email'] = $this->normalizeEmailConfig(is_array($config['email'] ?? null) ? $config['email'] : []);
        $config['sources'] = $this->normalizeSourcesConfig(is_array($config['sources'] ?? null) ? $config['sources'] : []);
        $config['searches'] = $searches;
        $config['filters'] = $this->buildLegacyFilters($searches, $legacyFilters);
        $config['keywords'] = $this->buildLegacyKeywords($searches, $legacyKeywords);

        return $config;
    }

    private function normalizeSearches(array $config): array
    {
        $searchesInput = $config['searches'] ?? [];

        if (!is_array($searchesInput) || $searchesInput === []) {
            $searchesInput = $this->legacySearchesFromConfig($config);
        }

        $legacyKeywords = is_array($config['keywords'] ?? null) ? $config['keywords'] : [];
        $legacyFilters = is_array($config['filters'] ?? null) ? $config['filters'] : [];
        $templates = $this->defaultSearchTemplatesByKey();
        $normalized = [];
        $usedKeys = [];

        foreach ($searchesInput as $index => $search) {
            if (!is_array($search)) {
                continue;
            }

            $requestedKey = $this->sanitizeSearchKey((string) ($search['key'] ?? ''), $index + 1);
            $key = $this->ensureUniqueSearchKey($requestedKey, $usedKeys);
            $template = $templates[$key] ?? null;

            $label = trim((string) ($search['label'] ?? ($template['label'] ?? '')));
            if ($label === '') {
                $label = $this->humanizeSearchKey($key);
            }

            $filtersInput = null;
            if (isset($search['filters']) && is_array($search['filters'])) {
                $filtersInput = $search['filters'];
            } elseif (isset($search['rules']) && is_array($search['rules'])) {
                $filtersInput = $search['rules'];
            }

            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'keywords' => $this->sanitizeStringList(
                    $search['keywords']
                    ?? $search['tags']
                    ?? $legacyKeywords[$key]
                    ?? ($template['keywords'] ?? [])
                ),
                'positive_support' => $this->sanitizeStringList(
                    $search['positive_support']
                    ?? ($search['rules']['positive_support'] ?? null)
                    ?? $legacyKeywords['positive_support']
                    ?? ($template['positive_support'] ?? [])
                ),
                'excluded' => $this->sanitizeStringList(
                    $search['excluded']
                    ?? ($search['rules']['excluded'] ?? null)
                    ?? $legacyKeywords['excluded']
                    ?? ($template['excluded'] ?? [])
                ),
                'filters' => $this->normalizeFiltersConfig(
                    is_array($filtersInput) ? $filtersInput : ($template['filters'] ?? $legacyFilters)
                ),
            ];
            $usedKeys[$key] = true;
        }

        return $normalized !== [] ? $normalized : $this->defaultSearches();
    }

    private function legacySearchesFromConfig(array $config): array
    {
        $keywords = is_array($config['keywords'] ?? null) ? $config['keywords'] : [];
        $searches = [];

        foreach (array_keys($keywords) as $key) {
            if (in_array($key, self::RESERVED_SEARCH_KEYS, true)) {
                continue;
            }

            $searches[] = [
                'key' => $key,
                'label' => $this->humanizeSearchKey($key),
            ];
        }

        return $searches !== [] ? $searches : $this->defaultSearches();
    }

    private function buildLegacyKeywords(array $searches, array $legacyKeywords): array
    {
        $positiveSupport = $this->sanitizeStringList($legacyKeywords['positive_support'] ?? []);
        $excluded = $this->sanitizeStringList($legacyKeywords['excluded'] ?? []);

        if ($positiveSupport === []) {
            $positiveSupport = $this->sanitizeStringList(array_merge(
                ...array_map(static fn (array $search): array => $search['positive_support'] ?? [], $searches)
            ));
        }

        if ($excluded === []) {
            $excluded = $this->sanitizeStringList(array_merge(
                ...array_map(static fn (array $search): array => $search['excluded'] ?? [], $searches)
            ));
        }

        $keywords = [
            'positive_support' => $positiveSupport,
            'excluded' => $excluded,
        ];

        foreach ($searches as $search) {
            $keywords[$search['key']] = $this->sanitizeStringList($search['keywords'] ?? []);
        }

        return $keywords;
    }

    private function buildLegacyFilters(array $searches, array $legacyFilters): array
    {
        if ($searches === []) {
            return $this->normalizeFiltersConfig($legacyFilters);
        }

        $thresholds = array_map(static fn (array $search): int => (int) ($search['filters']['score_threshold'] ?? 5), $searches);
        $maxAgeDays = array_map(static fn (array $search): int => (int) ($search['filters']['max_age_days'] ?? 90), $searches);
        $discardWithoutDate = array_map(
            static fn (array $search): bool => (bool) ($search['filters']['discard_without_posted_date'] ?? true),
            $searches
        );

        return $this->normalizeFiltersConfig([
            'score_threshold' => $thresholds === [] ? ($legacyFilters['score_threshold'] ?? 5) : min($thresholds),
            'max_age_days' => $maxAgeDays === [] ? ($legacyFilters['max_age_days'] ?? 90) : max($maxAgeDays),
            'discard_without_posted_date' => !in_array(false, $discardWithoutDate, true),
        ]);
    }

    private function sanitizeFixedShape(array $input, array $shape): array
    {
        $result = [];

        foreach ($shape as $key => $value) {
            if (!array_key_exists($key, $input)) {
                $result[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $result[$key] = is_array($input[$key]) ? $this->sanitizeFixedShape($input[$key], $value) : $value;
                continue;
            }

            $result[$key] = $input[$key];
        }

        return $result;
    }

    private function sanitizeSearchKey(string $value, int $index): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
        $value = trim($value, '_');

        if ($value === '' || in_array($value, self::RESERVED_SEARCH_KEYS, true)) {
            return 'search_' . $index;
        }

        return $value;
    }

    private function ensureUniqueSearchKey(string $key, array $usedKeys): string
    {
        if (!isset($usedKeys[$key])) {
            return $key;
        }

        $suffix = 2;
        while (isset($usedKeys[$key . '_' . $suffix])) {
            $suffix++;
        }

        return $key . '_' . $suffix;
    }

    private function humanizeSearchKey(string $key): string
    {
        $words = str_replace('_', ' ', trim($key));

        return $words === '' ? 'Nueva búsqueda' : ucwords($words);
    }

    private function sanitizeStringList(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $normalized = array_values(array_filter(array_map(static function ($item) {
            if (is_array($item)) {
                return null;
            }

            return trim((string) $item);
        }, $input), static fn (?string $item): bool => $item !== null && $item !== ''));

        return array_values(array_unique($normalized));
    }

    private function mergeRecursiveDistinct(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value) && !array_is_list($base[$key])) {
                $base[$key] = $this->mergeRecursiveDistinct($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function defaultSearchTemplatesByKey(): array
    {
        $templates = [];

        foreach ($this->defaultSearches() as $search) {
            $templates[$search['key']] = $search;
        }

        return $templates;
    }

    private function defaultSearches(): array
    {
        return [
            [
                'key' => 'spanish',
                'label' => 'Spanish',
                'keywords' => [
                    'spanish',
                    'spanish teacher',
                    'spanish language',
                    'spanish lecturer',
                    'AP Spanish',
                    'A-Level Spanish',
                    'IB Spanish',
                    'IGCSE Spanish',
                    'university',
                    'college',
                    'international school',
                    'high school',
                    'secondary school',
                ],
                'positive_support' => $this->defaultPositiveSupport(),
                'excluded' => $this->defaultExcluded(),
                'filters' => $this->defaultFiltersConfig(),
            ],
            [
                'key' => 'business',
                'label' => 'Business',
                'keywords' => [
                    'business',
                    'international business',
                    'business studies',
                    'business management',
                    'management',
                    'trade',
                    'commerce',
                    'e-commerce',
                    'international trade',
                    'AP Business',
                    'A-Level Business',
                    'IB Business Management',
                    'business school',
                    'international school',
                    'high school',
                    'secondary school',
                ],
                'positive_support' => $this->defaultPositiveSupport(),
                'excluded' => $this->defaultExcluded(),
                'filters' => $this->defaultFiltersConfig(),
            ],
        ];
    }

    private function defaultPositiveSupport(): array
    {
        return [
            'lecturer',
            'faculty',
            'assistant professor',
            'associate professor',
            'professor',
            'teaching fellow',
            'university',
            'college',
            'school of business',
        ];
    }

    private function defaultExcluded(): array
    {
        return [
            'preschool',
            'k12',
            'middle school',
            'secondary school',
            'high school',
            'homeroom',
            'training center',
            'sales',
            'business development',
            'recruiter',
            'agent',
            'internship',
            'intern',
            'marketing executive',
        ];
    }

    private function defaultFiltersConfig(): array
    {
        return [
            'score_threshold' => 5,
            'max_age_days' => 90,
            'discard_without_posted_date' => true,
        ];
    }

    private function normalizeEmailConfig(array $email): array
    {
        $defaults = $this->defaults()['email'];
        $sendMode = trim((string) ($email['send_mode'] ?? $defaults['send_mode']));
        $allowedSendModes = ['manual', 'automatic', 'manual_and_automatic'];

        return [
            'enabled' => (bool) ($email['enabled'] ?? $defaults['enabled']),
            'to' => $this->sanitizeEmail($email['to'] ?? $defaults['to']),
            'daily_send_time' => $this->sanitizeTime($email['daily_send_time'] ?? $defaults['daily_send_time']),
            'send_mode' => in_array($sendMode, $allowedSendModes, true) ? $sendMode : $defaults['send_mode'],
        ];
    }

    private function normalizeFiltersConfig(array $filters): array
    {
        $defaults = $this->defaultFiltersConfig();

        return [
            'score_threshold' => $this->clampInt($filters['score_threshold'] ?? $defaults['score_threshold'], 1, 100, $defaults['score_threshold']),
            'max_age_days' => $this->clampInt($filters['max_age_days'] ?? $defaults['max_age_days'], 1, 365, $defaults['max_age_days']),
            'discard_without_posted_date' => (bool) ($filters['discard_without_posted_date'] ?? $defaults['discard_without_posted_date']),
        ];
    }

    private function normalizeSourcesConfig(array $sources): array
    {
        $defaults = $this->defaults()['sources'];
        $normalized = [];

        foreach ($defaults as $key => $shape) {
            $source = is_array($sources[$key] ?? null) ? $sources[$key] : [];
            $normalized[$key] = [
                'enabled' => (bool) ($source['enabled'] ?? $shape['enabled']),
                'max_pages' => $this->clampInt($source['max_pages'] ?? $shape['max_pages'] ?? 1, 1, 50, (int) ($shape['max_pages'] ?? 1)),
                'max_results' => $this->clampInt($source['max_results'] ?? $shape['max_results'] ?? 20, 1, 500, (int) ($shape['max_results'] ?? 20)),
            ];

            foreach (['public_url', 'search_url'] as $field) {
                if (array_key_exists($field, $shape) || array_key_exists($field, $source)) {
                    $normalized[$key][$field] = $this->sanitizeHttpUrl($source[$field] ?? $shape[$field] ?? '');
                }
            }

            foreach (['search_keyword', 'client_id', 'api_limit', 'api_location'] as $field) {
                if (!array_key_exists($field, $shape) && !array_key_exists($field, $source)) {
                    continue;
                }

                if (in_array($field, ['client_id', 'api_limit'], true)) {
                    $normalized[$key][$field] = $this->clampInt(
                        $source[$field] ?? $shape[$field] ?? 1,
                        1,
                        1000,
                        (int) ($shape[$field] ?? 1)
                    );
                    continue;
                }

                $normalized[$key][$field] = trim((string) ($source[$field] ?? $shape[$field] ?? ''));
            }
        }

        return $normalized;
    }

    private function sanitizeEmail(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    private function sanitizeTime(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : '08:00';
    }

    private function sanitizeHttpUrl(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $isValid = filter_var($value, FILTER_VALIDATE_URL) !== false;
        $scheme = parse_url($value, PHP_URL_SCHEME);

        if (!$isValid || !in_array(Str::lower((string) $scheme), ['http', 'https'], true)) {
            return '';
        }

        return rtrim($value, '/');
    }

    private function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private function extractSearchKeys(array $config): array
    {
        $searches = is_array($config['searches'] ?? null) ? $config['searches'] : [];
        $keys = [];

        foreach ($searches as $search) {
            if (!is_array($search)) {
                continue;
            }

            $key = trim((string) ($search['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $keys[$key] = true;
        }

        return array_keys($keys);
    }

    private function purgeSearchHistory(array $removedSearchKeys): void
    {
        $removedSearchMap = array_fill_keys($removedSearchKeys, true);
        $removedJobIds = [];

        $jobs = $this->storage->load('jobs', []);
        [$jobs, $removedFromJobs] = $this->pruneJobsBySearch($jobs, $removedSearchMap);
        $this->storage->save('jobs', $jobs);
        foreach ($removedFromJobs as $jobId) {
            $removedJobIds[$jobId] = true;
        }

        $previewJobs = $this->storage->load('preview_jobs', []);
        [$previewJobs, $removedFromPreview] = $this->pruneJobsBySearch($previewJobs, $removedSearchMap);
        $this->storage->save('preview_jobs', $previewJobs);
        foreach ($removedFromPreview as $jobId) {
            $removedJobIds[$jobId] = true;
        }

        if ($removedJobIds !== []) {
            $sentJobs = $this->storage->load('sent_jobs', []);
            $sentJobs = array_values(array_filter($sentJobs, static function (array $record) use ($removedJobIds): bool {
                $jobId = trim((string) ($record['job_id'] ?? ''));

                return $jobId === '' || !isset($removedJobIds[$jobId]);
            }));
            $this->storage->save('sent_jobs', $sentJobs);
        }

        $runs = $this->storage->load('runs', []);
        foreach ($runs as &$run) {
            if (!is_array($run)) {
                continue;
            }

            [$acceptedBySearch, $removedAccepted] = $this->pruneSearchCounters($run['accepted_jobs_by_search'] ?? null, $removedSearchMap);
            if ($acceptedBySearch !== null) {
                $run['accepted_jobs_by_search'] = $acceptedBySearch;
                if (isset($run['accepted_jobs'])) {
                    $run['accepted_jobs'] = max(0, (int) $run['accepted_jobs'] - $removedAccepted);
                }
            }

            [$newBySearch, $removedNew] = $this->pruneSearchCounters($run['new_jobs_by_search'] ?? null, $removedSearchMap);
            if ($newBySearch !== null) {
                $run['new_jobs_by_search'] = $newBySearch;
                if (isset($run['new_jobs_count'])) {
                    $run['new_jobs_count'] = max(0, (int) $run['new_jobs_count'] - $removedNew);
                }
            }
        }
        unset($run);
        $this->storage->save('runs', $runs);

        $logs = $this->storage->load('logs', []);
        foreach ($logs as &$log) {
            if (!is_array($log) || !is_array($log['context'] ?? null)) {
                continue;
            }

            $context = $log['context'];
            [$acceptedBySearch] = $this->pruneSearchCounters($context['accepted_by_search'] ?? null, $removedSearchMap);
            if ($acceptedBySearch !== null) {
                $context['accepted_by_search'] = $acceptedBySearch;
            }

            [$newBySearch] = $this->pruneSearchCounters($context['new_jobs_by_search'] ?? null, $removedSearchMap);
            if ($newBySearch !== null) {
                $context['new_jobs_by_search'] = $newBySearch;
            }

            $log['context'] = $context;
        }
        unset($log);
        $this->storage->save('logs', $logs);
    }

    /**
     * @return array{0: array<int, array>, 1: array<int, string>}
     */
    private function pruneJobsBySearch(array $jobs, array $removedSearchMap): array
    {
        $filteredJobs = [];
        $removedJobIds = [];

        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            if ($this->belongsToRemovedSearch($job, $removedSearchMap)) {
                $jobId = trim((string) ($job['id'] ?? ''));
                if ($jobId !== '') {
                    $removedJobIds[] = $jobId;
                }
                continue;
            }

            $filteredJobs[] = $job;
        }

        return [$filteredJobs, array_values(array_unique($removedJobIds))];
    }

    private function belongsToRemovedSearch(array $job, array $removedSearchMap): bool
    {
        $category = trim((string) ($job['category'] ?? ''));
        if ($category !== '' && isset($removedSearchMap[$category])) {
            return true;
        }

        $rawSearchCategory = trim((string) ($job['raw_meta']['search_category'] ?? ''));
        if ($rawSearchCategory !== '' && isset($removedSearchMap[$rawSearchCategory])) {
            return true;
        }

        return false;
    }

    /**
     * @return array{0: ?array<string, int>, 1: int}
     */
    private function pruneSearchCounters(mixed $counters, array $removedSearchMap): array
    {
        if (!is_array($counters)) {
            return [null, 0];
        }

        $filtered = [];
        $removedTotal = 0;

        foreach ($counters as $key => $value) {
            $normalizedKey = trim((string) $key);
            $count = is_numeric($value) ? max(0, (int) $value) : 0;

            if ($normalizedKey !== '' && isset($removedSearchMap[$normalizedKey])) {
                $removedTotal += $count;
                continue;
            }

            if ($normalizedKey === '') {
                continue;
            }

            $filtered[$normalizedKey] = $count;
        }

        ksort($filtered);

        return [$filtered, $removedTotal];
    }

    private function defaults(): array
    {
        $defaultSearches = $this->defaultSearches();

        return [
            'email' => [
                'enabled' => true,
                'to' => '',
                'daily_send_time' => '08:00',
                'send_mode' => 'manual_and_automatic',
            ],
            'filters' => $this->defaultFiltersConfig(),
            'searches' => $defaultSearches,
            'keywords' => [
                'positive_support' => $this->defaultPositiveSupport(),
                'excluded' => $this->defaultExcluded(),
                'spanish' => $defaultSearches[0]['keywords'],
                'business' => $defaultSearches[1]['keywords'],
            ],
            'sources' => [
                'unnc' => [
                    'enabled' => true,
                    'max_pages' => 1,
                    'max_results' => 20,
                    'public_url' => 'https://www.nottingham.edu.cn/en/jobs/current-vacancies/current-vacancies.aspx',
                ],
                'higheredjobs' => [
                    'enabled' => true,
                    'max_pages' => 2,
                    'max_results' => 40,
                    'public_url' => 'https://www.higheredjobs.com/international/search.cfm?CountryCode=44',
                ],
                'chinauniversityjobs' => [
                    'enabled' => true,
                    'max_pages' => 5,
                    'max_results' => 100,
                    'search_url' => 'https://www.chinauniversityjobs.com/jobs/?s=spanish&location=&category=&post_type=noo_job',
                    'public_url' => 'https://www.chinauniversityjobs.com/jobs/?s=spanish&location=&category=&post_type=noo_job',
                ],
                'chinajob' => [
                    'enabled' => true,
                    'max_pages' => 2,
                    'max_results' => 30,
                    'search_url' => 'https://www.chinajob.com/job/index.php?q=spanish&l=&f=Teacher/Instructor/Professor/Scholar&m=s',
                    'public_url' => 'https://www.chinajob.com/job/index.php',
                ],
                'hiredchina' => [
                    'enabled' => true,
                    'max_pages' => 1,
                    'max_results' => 40,
                    'search_url' => 'https://www.hiredchina.com/api/v1/jobs',
                    'search_keyword' => 'spanish teacher',
                    'api_limit' => 30,
                    'client_id' => 2,
                    'public_url' => 'https://www.hiredchina.com/jobs',
                ],
                'jobscina' => [
                    'enabled' => true,
                    'max_pages' => 2,
                    'max_results' => 40,
                    'search_url' => 'https://jobscina.com/jobs/list?s=spanish&l=',
                    'public_url' => 'https://jobscina.com/jobs/list?s=spanish&l=',
                ],
                'echinacities' => [
                    'enabled' => true,
                    'max_pages' => 3,
                    'max_results' => 60,
                    'public_url' => 'https://jobs.echinacities.com/jobs/search?keyword=spanish',
                ],
                'jooble' => [
                    'enabled' => true,
                    'max_pages' => 1,
                    'max_results' => 40,
                    'api_location' => 'China',
                    'search_url' => 'https://jooble.org/jobs-spanish-teaching/China',
                    'public_url' => 'https://jooble.org/jobs-spanish-teaching/China',
                ],
                'chinateachjobs' => [
                    'enabled' => true,
                    'max_pages' => 2,
                    'max_results' => 40,
                    'search_url' => 'https://www.chinateachjobs.com/jobs/?s=spanish&category=',
                    'public_url' => 'https://www.chinateachjobs.com/jobs/?s=spanish&category=',
                ],
            ],
        ];
    }
}
