<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\DTO\ScrapeResult;
use App\Utils\BlockedSourceException;
use App\Utils\DateParser;
use App\Utils\HttpException;
use App\Utils\Str;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DomCrawler\Crawler;

final class ChinaJobScraper extends AbstractScraper
{
    public function getSourceKey(): string
    {
        return 'chinajob';
    }

    public function scrape(array $config): ScrapeResult
    {
        $sourceConfig = $config['sources'][$this->getSourceKey()] ?? [];
        $maxPages = max(1, (int) ($sourceConfig['max_pages'] ?? 2));
        $maxResults = max(1, (int) ($sourceConfig['max_results'] ?? 30));
        $searchUrlTemplate = trim((string) ($sourceConfig['search_url'] ?? 'https://www.chinajob.com/job/index.php?q=&l=&f=Teacher/Instructor/Professor/Scholar&m=s'));
        $maxAgeDays = max(1, (int) ($config['filters']['max_age_days'] ?? 90));
        $searchPlans = $this->buildKeywordPlans($config, [
            'spanish' => ['spanish'],
            'business' => ['business', 'international business', 'management', 'trade', 'commerce', 'e-commerce'],
        ]);

        $jobs = [];
        $seenIds = [];
        $categoryCounts = $this->initializeCategoryCounts($searchPlans);
        $hadFetchIssues = false;

        try {
            foreach ($searchPlans as $plan) {
                if (count($jobs) >= $maxResults) {
                    break;
                }

                $planCapacity = $this->computeCategoryPlanCapacity(
                    $categoryCounts,
                    (string) $plan['category'],
                    $maxResults,
                    count($jobs)
                );
                if ($planCapacity <= 0) {
                    continue;
                }

                $searchUrl = $this->replaceQueryParameter($searchUrlTemplate, 'q', (string) $plan['keyword']);
                $planAdded = 0;

                for ($page = 1; $page <= $maxPages; $page++) {
                    $pageUrl = $this->buildPageUrl($searchUrl, $page);
                    try {
                        $crawler = $this->fetchPageCrawler($pageUrl);
                    } catch (\Throwable $exception) {
                        $hadFetchIssues = true;
                        $this->logger->warning('ChinaJob página no disponible para el término actual', [
                            'source' => $this->getSourceKey(),
                            'search_keyword' => $plan['keyword'] ?? '',
                            'page' => $page,
                            'message' => $exception->getMessage(),
                        ]);
                        break;
                    }
                    $items = $crawler->filter('.cj-job-item');

                    if ($items->count() === 0) {
                        break;
                    }

                    $pageAdded = 0;

                    foreach ($items as $element) {
                        $item = new Crawler($element, $pageUrl);
                        $job = $this->extractListJob($item, $pageUrl);

                        if ($job === null) {
                            continue;
                        }

                        $jobId = (string) ($job['raw_meta']['chinajob_id'] ?? '');
                        $seenKey = $jobId !== '' ? $jobId : $job['url'];
                        if (isset($seenIds[$seenKey])) {
                            continue;
                        }

                        $seenIds[$seenKey] = true;
                        $pageAdded++;
                        $planAdded++;
                        $categoryCounts[(string) $plan['category']]++;
                        $job['raw_meta']['search_keyword'] = $plan['keyword'];
                        $job['raw_meta']['search_category'] = $plan['category'];

                        $detailUrl = trim((string) ($job['url'] ?? ''));
                        if (
                            $detailUrl !== ''
                            && $this->shouldFetchDetail($job['posted_date'] ?? null, $maxAgeDays)
                        ) {
                            $detail = $this->fetchDetailData($detailUrl);
                            if ($detail !== null) {
                                $job = $this->mergeDetail($job, $detail);
                            }
                        }

                        $jobs[] = $job;

                        if (count($jobs) >= $maxResults) {
                            break 3;
                        }

                        if ($planAdded >= $planCapacity) {
                            break;
                        }
                    }

                    if ($pageAdded === 0 || $planAdded >= $planCapacity) {
                        break;
                    }
                }
            }

            if ($jobs !== [] && $hadFetchIssues) {
                return new ScrapeResult(
                    $this->getSourceKey(),
                    'partial',
                    $jobs,
                    'Algunas búsquedas fallaron, pero se recuperaron resultados parciales.'
                );
            }

            return new ScrapeResult($this->getSourceKey(), $jobs === [] ? 'empty' : 'ok', $jobs);
        } catch (\Throwable $exception) {
            $status = $exception instanceof \App\Utils\BlockedSourceException ? 'blocked' : 'error';
            $this->logger->warning('ChinaJob no disponible', ['message' => $exception->getMessage()]);
            return new ScrapeResult($this->getSourceKey(), $status, [], $exception->getMessage());
        }
    }

    private function extractListJob(Crawler $item, string $pageUrl): ?array
    {
        $left = $item->filter('.cj-job-item-left')->first();
        if ($left->count() === 0) {
            return null;
        }

        $title = $this->normalizeText($this->textOrNull($item, '.job-title'));
        $detailUrl = $this->extractDetailUrl((string) $left->attr('onclick'), $pageUrl);

        if ($title === '' || $detailUrl === '') {
            return null;
        }

        $jobPost = $this->normalizeText($this->textOrNull($item, '.job-post'));
        $institution = $this->normalizeText($this->textOrNull($item, '.job-post a'));
        $info = $item->filter('.job-info .info-txt')->each(fn (Crawler $node): string => $this->normalizeText($node->text()));

        $jobType = $info[0] ?? null;
        $location = $info[1] ?? null;
        $salary = $info[2] ?? null;
        $postedDate = $this->parsePostedDate($jobPost);
        $jobId = $this->extractQueryParam($detailUrl, 'jobid');
        $employerUrl = $this->attrOrNull($item, '.job-post a', 'href');

        $descriptionParts = array_filter([
            $jobType,
            $location,
            $salary,
            $jobPost,
        ], static fn (?string $value): bool => trim((string) $value) !== '');

        return [
            'source' => $this->getSourceKey(),
            'title' => $title,
            'institution' => $institution,
            'location' => $location,
            'url' => $detailUrl,
            'description' => Str::slice(implode(' | ', $descriptionParts), 0, 1200),
            'posted_date' => $postedDate,
            'closing_date' => null,
            'raw_meta' => [
                'chinajob_id' => $jobId !== '' ? $jobId : null,
                'employer_url' => $employerUrl !== null ? $this->absoluteUrl($pageUrl, $employerUrl) : null,
                'listing_page_url' => $pageUrl,
                'job_type' => $jobType,
                'salary' => $salary,
            ],
        ];
    }

    private function fetchDetailData(string $detailUrl): ?array
    {
        try {
            $crawler = $this->fetchPageCrawler($detailUrl);
        } catch (\Throwable) {
            return null;
        }

        if ($crawler->filter('.pop-alert')->count() > 0) {
            return null;
        }

        $summary = [];
        foreach ($crawler->filter('.cj-job-side-list li') as $element) {
            $node = new Crawler($element, $detailUrl);
            $label = $this->normalizeText($this->textOrNull($node, '.txt-main'));
            $value = $this->normalizeText($this->textOrNull($node, '.txt-sub'));

            if ($label === '' || $value === '') {
                continue;
            }

            $summary[$label] = $value;
        }

        $descriptionBlocks = [];
        foreach ($crawler->filter('.cj-job-desc') as $element) {
            $block = new Crawler($element, $detailUrl);
            $html = $block->html();
            if ($html === null) {
                continue;
            }

            $text = $this->normalizeText(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));
            if ($text !== '') {
                $descriptionBlocks[] = $text;
            }
        }

        return [
            'institution' => $summary['Company'] ?? null,
            'location' => $summary['Location'] ?? null,
            'job_type' => $summary['Job Type'] ?? ($summary['Job Type '] ?? null),
            'salary' => $summary['Salary Range'] ?? null,
            'posted_date' => $summary['Date Posted'] ?? null,
            'description' => $descriptionBlocks === [] ? null : Str::slice(implode("\n\n", $descriptionBlocks), 0, 4000),
        ];
    }

    private function mergeDetail(array $job, array $detail): array
    {
        foreach (['institution', 'location'] as $field) {
            $candidate = trim((string) ($detail[$field] ?? ''));
            if ($candidate !== '') {
                $job[$field] = $candidate;
            }
        }

        $detailDescription = trim((string) ($detail['description'] ?? ''));
        if ($detailDescription !== '') {
            $job['description'] = $detailDescription;
        }

        $detailPostedDate = $this->parsePostedDate((string) ($detail['posted_date'] ?? ''));
        if ($detailPostedDate !== null) {
            $job['posted_date'] = $detailPostedDate;
        }

        $job['raw_meta']['job_type'] = trim((string) ($detail['job_type'] ?? $job['raw_meta']['job_type'] ?? '')) ?: ($job['raw_meta']['job_type'] ?? null);
        $job['raw_meta']['salary'] = trim((string) ($detail['salary'] ?? $job['raw_meta']['salary'] ?? '')) ?: ($job['raw_meta']['salary'] ?? null);

        return $job;
    }

    private function buildPageUrl(string $baseUrl, int $page): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            return $baseUrl;
        }

        if (preg_match('/([?&])p=\d+\b/', $baseUrl) === 1) {
            return (string) preg_replace('/([?&])p=\d+\b/', '${1}p=' . $page, $baseUrl, 1);
        }

        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . 'p=' . $page;
    }

    private function fetchPageCrawler(string $url): Crawler
    {
        return new Crawler($this->fetchPageHtml($url), $url);
    }

    private function fetchPageHtml(string $url): string
    {
        $fallback = $this->fetchViaCurl($url);
        if ($fallback !== null) {
            return $fallback;
        }

        try {
            return $this->fetchHtml($url);
        } catch (\Throwable $exception) {
            throw new HttpException($exception->getMessage(), 0, $exception);
        }
    }

    private function extractDetailUrl(string $onclick, string $baseUrl): string
    {
        if ($onclick === '') {
            return '';
        }

        if (preg_match("/location\\.href='([^']+)'/", $onclick, $matches) !== 1) {
            return '';
        }

        $relativeUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        if (str_starts_with($relativeUrl, 'http://') || str_starts_with($relativeUrl, 'https://')) {
            return $relativeUrl;
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $relativeUrl;
        }

        $path = $parts['path'] ?? '/job/index.php';
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return sprintf(
            '%s://%s%s/%s',
            $parts['scheme'],
            $parts['host'],
            $directory === '' ? '' : $directory,
            ltrim($relativeUrl, '/')
        );
    }

    private function extractQueryParam(string $url, string $name): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        return trim((string) ($query[$name] ?? ''));
    }

    private function parsePostedDate(string $value): ?string
    {
        $value = $this->normalizeText($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/posted\s+on\s+(.+?)\s+by\b/i', $value, $matches) === 1) {
            return DateParser::parse($matches[1]);
        }

        if (preg_match('/posted\s+(.+?)\s+by\b/i', $value, $matches) === 1) {
            return DateParser::parse($matches[1]);
        }

        return DateParser::parse($value);
    }

    private function fetchViaCurl(string $url): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }

        $headersFile = tempnam(sys_get_temp_dir(), 'chinajob_headers_');
        $bodyFile = tempnam(sys_get_temp_dir(), 'chinajob_body_');
        if ($headersFile === false || $bodyFile === false) {
            return null;
        }

        $curlBinary = $this->resolveCurlBinary();
        if ($curlBinary === null) {
            @unlink($headersFile);
            @unlink($bodyFile);
            return null;
        }

        $command = sprintf(
            '%s -sS -L --connect-timeout 10 --max-time 25 -A %s -H %s -D %s -o %s %s',
            escapeshellarg($curlBinary),
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
            @unlink($headersFile);
            @unlink($bodyFile);
            return null;
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $headersRaw = is_file($headersFile) ? (file_get_contents($headersFile) ?: '') : '';
        $body = is_file($bodyFile) ? (file_get_contents($bodyFile) ?: '') : '';
        @unlink($headersFile);
        @unlink($bodyFile);

        if ($exitCode !== 0 && trim($body) === '') {
            return null;
        }

        if (!preg_match_all('/^HTTP\/[0-9.]+\s+(\d{3})/mi', $headersRaw, $matches) || $matches[1] === []) {
            return null;
        }

        $status = (int) end($matches[1]);
        if (in_array($status, [403, 429, 503], true) || $this->looksBlocked($body)) {
            throw new BlockedSourceException(sprintf('Fuente bloqueada o protegida [%s]', $status));
        }

        if ($status >= 400 || trim($body) === '') {
            throw new HttpException(sprintf('Respuesta HTTP no usable [%s]', $status));
        }

        return $body;
    }

    private function resolveCurlBinary(): ?string
    {
        $candidates = [];

        if (PHP_OS_FAMILY === 'Windows') {
            $systemRoot = (string) ($_SERVER['SystemRoot'] ?? $_ENV['SystemRoot'] ?? 'C:\Windows');
            $candidates[] = rtrim($systemRoot, '\\/') . '\System32\curl.exe';
            $candidates[] = 'C:\Windows\System32\curl.exe';
        }

        $candidates[] = 'curl';
        $candidates[] = 'curl.exe';

        foreach ($candidates as $candidate) {
            if ($candidate === 'curl' || $candidate === 'curl.exe') {
                return $candidate;
            }

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function shouldFetchDetail(?string $postedDate, int $maxAgeDays): bool
    {
        if ($postedDate === null) {
            return true;
        }

        return !DateParser::isOlderThanDays($postedDate, $maxAgeDays);
    }

    private function normalizeText(?string $value): string
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5);
        $value = preg_replace('/\x{200B}|\x{FEFF}/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B\x{00A0}");
    }
}
