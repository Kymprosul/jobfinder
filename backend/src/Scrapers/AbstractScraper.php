<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\Services\LoggerService;
use App\Utils\BlockedSourceException;
use App\Utils\HttpException;
use App\Utils\TextNormalizer;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DomCrawler\Crawler;

abstract class AbstractScraper implements ScraperInterface
{
    protected Client $client;

    public function __construct(protected readonly LoggerService $logger)
    {
        $this->client = new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
            'http_errors' => false,
            'verify' => filter_var($_ENV['HTTP_VERIFY_SSL'] ?? 'false', FILTER_VALIDATE_BOOL),
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; JobfinderBot/1.0; +https://localhost)',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ]);
    }

    protected function fetchHtml(string $url): string
    {
        try {
            $response = $this->client->get($url);
            $statusCode = $response->getStatusCode();
            $html = (string) $response->getBody();
        } catch (GuzzleException $exception) {
            $fallback = $this->fetchHtmlViaCurl($url);
            if ($fallback === null) {
                throw new HttpException($exception->getMessage(), 0, $exception);
            }

            $statusCode = $fallback['status'];
            $html = $fallback['body'];
        }

        if (in_array($statusCode, [403, 429, 503], true) || $this->looksBlocked($html)) {
            throw new BlockedSourceException(sprintf('Fuente bloqueada o protegida [%s]', $statusCode));
        }

        if ($statusCode >= 400 || trim($html) === '') {
            throw new HttpException(sprintf('Respuesta HTTP no usable [%s]', $statusCode));
        }

        return $html;
    }

    protected function fetchCrawler(string $url): Crawler
    {
        $html = $this->fetchHtml($url);
        return new Crawler($html, $url);
    }

    protected function textOrNull(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();
            return $node->count() > 0 ? trim($node->text()) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function attrOrNull(Crawler $crawler, string $selector, string $attribute): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();
            return $node->count() > 0 ? trim((string) $node->attr($attribute)) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function absoluteUrl(string $baseUrl, ?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return $baseUrl;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $prefix = sprintf('%s://%s', $parts['scheme'], $parts['host']);
        return str_starts_with($url, '/') ? $prefix . $url : $prefix . '/' . ltrim($url, '/');
    }

    protected function looksBlocked(string $html): bool
    {
        $needles = [
            'captcha',
            'incapsula',
            'access denied',
            'request unsuccessful',
            'bot verification',
            'cloudflare',
        ];

        $haystack = strtolower($html);
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function buildKeywordPlans(
        array $config,
        array $seedByCategory,
        int $customKeywordsPerCategory = 2,
        int $maxPlans = 8
    ): array {
        $plans = [];

        foreach ($seedByCategory as $category => $keywords) {
            foreach ($keywords as $keyword) {
                $plans[] = [
                    'category' => $category,
                    'keyword' => trim((string) $keyword),
                ];
            }
        }

        $configuredSearchKeys = $this->configuredSearchKeys($config, array_keys($seedByCategory));

        foreach ($configuredSearchKeys as $category) {
            foreach (array_slice($config['keywords'][$category] ?? [], 0, $customKeywordsPerCategory) as $keyword) {
                $plans[] = [
                    'category' => $category,
                    'keyword' => trim((string) $keyword),
                ];
            }
        }

        $uniquePlans = $this->uniqueKeywordPlans($plans, PHP_INT_MAX);
        $plansByCategory = [];

        foreach ($uniquePlans as $plan) {
            $plansByCategory[$plan['category']][] = $plan;
        }

        $categoryOrder = $configuredSearchKeys;
        foreach (array_keys($plansByCategory) as $category) {
            if (!in_array($category, $categoryOrder, true)) {
                $categoryOrder[] = $category;
            }
        }

        $interleaved = [];
        for ($index = 0; count($interleaved) < $maxPlans; $index++) {
            $addedInRound = false;

            foreach ($categoryOrder as $category) {
                if (!isset($plansByCategory[$category][$index])) {
                    continue;
                }

                $interleaved[] = $plansByCategory[$category][$index];
                $addedInRound = true;

                if (count($interleaved) >= $maxPlans) {
                    break 2;
                }
            }

            if (!$addedInRound) {
                break;
            }
        }

        return $interleaved;
    }

    protected function initializeCategoryCounts(array $plans): array
    {
        $counts = [];

        foreach ($plans as $plan) {
            $category = trim((string) ($plan['category'] ?? ''));
            if ($category === '' || array_key_exists($category, $counts)) {
                continue;
            }

            $counts[$category] = 0;
        }

        return $counts;
    }

    protected function uniqueKeywordPlans(array $plans, int $maxPlans = 8): array
    {
        $unique = [];

        foreach ($plans as $plan) {
            $keyword = trim((string) ($plan['keyword'] ?? ''));
            $category = trim((string) ($plan['category'] ?? ''));
            if ($keyword === '' || $category === '') {
                continue;
            }

            $key = $category . '|' . TextNormalizer::normalize($keyword);
            $unique[$key] = [
                'category' => $category,
                'keyword' => $keyword,
            ];
        }

        return array_slice(array_values($unique), 0, $maxPlans);
    }

    protected function computeCategoryPlanCapacity(
        array $categoryCounts,
        string $category,
        int $maxResults,
        int $currentTotal
    ): int {
        $remaining = $maxResults - $currentTotal;
        if ($remaining <= 0) {
            return 0;
        }

        $untouchedOtherCategories = array_filter(
            $categoryCounts,
            static fn (int $count, string $key): bool => $key !== $category && $count === 0,
            ARRAY_FILTER_USE_BOTH
        );

        if ($untouchedOtherCategories === []) {
            return $remaining;
        }

        $categoryQuota = (int) ceil($maxResults / max(1, count($categoryCounts)));
        $availableForCategory = max(0, $categoryQuota - (int) ($categoryCounts[$category] ?? 0));

        return min($remaining, $availableForCategory);
    }

    protected function configuredSearchKeys(array $config, array $fallback = []): array
    {
        $searches = $config['searches'] ?? [];
        if (is_array($searches) && $searches !== []) {
            $keys = array_values(array_filter(array_map(static function ($search): string {
                return is_array($search) ? trim((string) ($search['key'] ?? '')) : '';
            }, $searches)));

            if ($keys !== []) {
                return $keys;
            }
        }

        $legacyKeys = [];
        foreach (array_keys($config['keywords'] ?? []) as $key) {
            if (in_array($key, ['positive_support', 'excluded'], true)) {
                continue;
            }

            $legacyKeys[] = $key;
        }

        if ($legacyKeys !== []) {
            return $legacyKeys;
        }

        return $fallback;
    }

    protected function replaceQueryParameter(string $url, string $parameter, string $value): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query[$parameter] = $value;

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        $queryString = http_build_query($query);

        if ($host === '') {
            return $path . ($queryString !== '' ? '?' . $queryString : '') . $fragment;
        }

        return sprintf(
            '%s://%s%s%s%s%s',
            $scheme,
            $host,
            $port,
            $path,
            $queryString !== '' ? '?' . $queryString : '',
            $fragment
        );
    }

    private function fetchHtmlViaCurl(string $url): ?array
    {
        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('proc_open')) {
            return null;
        }

        $headersFile = tempnam(sys_get_temp_dir(), 'jobfinder_headers_');
        $bodyFile = tempnam(sys_get_temp_dir(), 'jobfinder_body_');
        if ($headersFile === false || $bodyFile === false) {
            return null;
        }

        $command = sprintf(
            'curl.exe -sS -L --connect-timeout 10 --max-time 20 -A %s -H %s -D %s -o %s %s',
            escapeshellarg('Mozilla/5.0 (compatible; JobfinderBot/1.0; +https://localhost)'),
            escapeshellarg('Accept-Language: en-US,en;q=0.9'),
            escapeshellarg($headersFile),
            escapeshellarg($bodyFile),
            escapeshellarg($url)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $headers = is_file($headersFile) ? (file_get_contents($headersFile) ?: '') : '';
        $body = is_file($bodyFile) ? (file_get_contents($bodyFile) ?: '') : '';
        @unlink($headersFile);
        @unlink($bodyFile);

        if ($exitCode !== 0 && trim($body) === '' && trim($stdout) === '') {
            return null;
        }

        if (!preg_match_all('/^HTTP\/[0-9.]+\s+(\d{3})/mi', $headers, $matches) || $matches[1] === []) {
            return null;
        }

        $status = (int) end($matches[1]);

        return [
            'status' => $status,
            'body' => $body,
        ];
    }
}
