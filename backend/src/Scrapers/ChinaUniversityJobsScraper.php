<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\DTO\ScrapeResult;
use App\Utils\BlockedSourceException;
use App\Utils\DateParser;
use App\Utils\Str;
use Symfony\Component\DomCrawler\Crawler;

final class ChinaUniversityJobsScraper extends AbstractScraper
{
    private const MIRROR_PREFIX = 'https://r.jina.ai/http://';

    public function getSourceKey(): string
    {
        return 'chinauniversityjobs';
    }

    public function scrape(array $config): ScrapeResult
    {
        $sourceConfig = $config['sources'][$this->getSourceKey()] ?? [];
        $maxPages = max(1, (int) ($sourceConfig['max_pages'] ?? 5));
        $maxResults = max(1, (int) ($sourceConfig['max_results'] ?? 100));
        $searchUrlTemplate = trim((string) ($sourceConfig['search_url'] ?? 'https://www.chinauniversityjobs.com/jobs/?s=&location=&category=&post_type=noo_job'));
        $searchPlans = $this->buildKeywordPlans($config, [
            'spanish' => ['spanish'],
            'business' => ['business', 'international business', 'management', 'trade', 'commerce', 'e-commerce'],
        ], 2, 10);

        $jobs = [];
        $seen = [];
        $categoryCounts = $this->initializeCategoryCounts($searchPlans);
        $usedMirror = false;
        $sawUsableSearch = false;
        $lastException = null;

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

            $searchUrl = $this->replaceQueryParameter($searchUrlTemplate, 's', (string) $plan['keyword']);

            try {
                $planJobs = $this->scrapeDirect($config, $searchUrl, $maxPages, $planCapacity);
                $sawUsableSearch = true;
            } catch (\Throwable $exception) {
                $lastException = $exception;

                try {
                    $planJobs = $this->scrapeViaMirror($searchUrl, $maxPages, $planCapacity);
                    $usedMirror = true;
                    $sawUsableSearch = true;
                } catch (\Throwable $fallbackException) {
                    continue;
                }
            }

            $planAdded = 0;
            foreach ($planJobs as $job) {
                $url = trim((string) ($job['url'] ?? ''));
                if ($url === '' || isset($seen[$url])) {
                    continue;
                }

                $seen[$url] = true;
                $job['raw_meta']['search_keyword'] = $plan['keyword'];
                $job['raw_meta']['search_category'] = $plan['category'];
                $planAdded++;
                $categoryCounts[(string) $plan['category']]++;
                $jobs[] = $job;

                if (count($jobs) >= $maxResults || $planAdded >= $planCapacity) {
                    break;
                }
            }
        }

        if ($jobs !== []) {
            return new ScrapeResult(
                $this->getSourceKey(),
                $usedMirror ? 'partial' : 'ok',
                $jobs,
                $usedMirror ? 'Acceso directo bloqueado en parte; usando fallback publico externo' : null
            );
        }

        if ($sawUsableSearch) {
            return new ScrapeResult($this->getSourceKey(), 'empty', []);
        }

        if ($lastException !== null) {
            $status = $lastException instanceof BlockedSourceException ? 'blocked' : 'error';
            $this->logger->warning('ChinaUniversityJobs no disponible', ['message' => $lastException->getMessage()]);
            return new ScrapeResult($this->getSourceKey(), $status, [], $lastException->getMessage());
        }

        return new ScrapeResult($this->getSourceKey(), 'empty', []);
    }

    private function scrapeDirect(array $config, string $searchUrl, int $maxPages, int $maxResults): array
    {
        $jobs = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $url = $this->buildPageUrl($page, $searchUrl);
            $crawler = $this->fetchCrawler($url);
            $pageJobs = $this->parseHtmlPage($crawler, $maxResults - count($jobs));
            if ($pageJobs === []) {
                break;
            }

            $jobs = array_merge($jobs, $pageJobs);
            if (count($jobs) >= $maxResults) {
                break;
            }

            $oldEnoughToStop = count(array_filter(
                $pageJobs,
                static fn (array $job): bool => isset($job['posted_date']) && DateParser::isOlderThanDays($job['posted_date'], (int) ($config['filters']['max_age_days'] ?? 90))
            )) === count($pageJobs);

            if ($oldEnoughToStop) {
                break;
            }
        }

        return $jobs;
    }

    private function scrapeViaMirror(string $searchUrl, int $maxPages, int $maxResults): array
    {
        $jobs = [];
        $seen = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $url = $this->buildPageUrl($page, $searchUrl);
            $markdown = $this->fetchMirrorMarkdown($url);
            $pageJobs = $this->parseMirrorPage($markdown, $maxResults - count($jobs));

            if ($pageJobs === []) {
                break;
            }

            foreach ($pageJobs as $job) {
                if (isset($seen[$job['url']])) {
                    continue;
                }

                $seen[$job['url']] = true;
                $jobs[] = $job;

                if (count($jobs) >= $maxResults) {
                    break 2;
                }
            }
        }

        return $jobs;
    }

    private function buildPageUrl(int $page, string $searchUrl): string
    {
        $baseUrl = trim($searchUrl);
        if ($baseUrl === '') {
            $baseUrl = 'https://www.chinauniversityjobs.com/jobs/';
        }

        if ($page === 1) {
            return $baseUrl;
        }

        $parts = parse_url($baseUrl);
        if ($parts === false) {
            return $baseUrl;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['paged'] = (string) $page;

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'www.chinauniversityjobs.com';
        $path = $parts['path'] ?? '/jobs/';
        $queryString = http_build_query($query);

        return sprintf('%s://%s%s%s', $scheme, $host, $path, $queryString !== '' ? '?' . $queryString : '');
    }

    private function parseHtmlPage(Crawler $crawler, int $remaining): array
    {
        $jobs = [];
        $seen = [];

        foreach ($crawler->filter('h3 a') as $linkNode) {
            if (count($jobs) >= $remaining) {
                break;
            }

            $linkCrawler = new Crawler($linkNode, $crawler->getUri());
            $title = trim($linkCrawler->text(''));
            $url = $this->absoluteUrl($crawler->getUri(), $linkCrawler->attr('href'));

            if ($title === '' || isset($seen[$url])) {
                continue;
            }

            $containerText = $this->containerText($linkNode, 2);
            $meta = $this->extractMetaLines($containerText, $title);
            $detail = $this->scrapeHtmlDetail($url);

            $jobs[] = $this->buildJobPayload($title, $url, $meta, $detail, [
                'listing_text' => $containerText,
            ]);

            $seen[$url] = true;
        }

        return $jobs;
    }

    private function parseMirrorPage(string $markdown, int $remaining): array
    {
        $jobs = [];
        $lines = preg_split('/\R/u', $markdown) ?: [];

        for ($index = 0; $index < count($lines); $index++) {
            if (count($jobs) >= $remaining) {
                break;
            }

            if (preg_match('/^### \[(.+?)\]\((https:\/\/www\.chinauniversityjobs\.com\/jobs\/[^ )]+)/', trim($lines[$index]), $matches) !== 1) {
                continue;
            }

            $title = trim($matches[1]);
            $url = trim($matches[2]);

            $metaLine = $this->nextMeaningfulLine($lines, $index + 1);
            $ageLine = $this->nextMeaningfulLine($lines, ($metaLine['index'] ?? $index) + 1);

            $meta = $this->extractMirrorListingMeta(
                (string) ($metaLine['line'] ?? ''),
                (string) ($ageLine['line'] ?? '')
            );
            $detail = [];

            $jobs[] = $this->buildJobPayload($title, $url, $meta, $detail, [
                'mirror_mode' => true,
                'mirror_listing_line' => (string) ($metaLine['line'] ?? ''),
                'mirror_age_line' => (string) ($ageLine['line'] ?? ''),
            ]);
        }

        return $jobs;
    }

    private function scrapeHtmlDetail(string $url): array
    {
        try {
            $crawler = $this->fetchCrawler($url);
        } catch (\Throwable) {
            return [];
        }

        $text = preg_replace('/\s+/u', ' ', trim($crawler->text())) ?? '';
        preg_match_all('/([A-Z][a-z]{2,8}\s+\d{1,2},\s+\d{4})/u', $text, $dates);

        $institution = $this->textOrNull($crawler, '.company, .employer, h2 + p') ?? '';
        $description = Str::slice($text, 0, 3000);

        return [
            'institution' => $institution,
            'description' => $description,
            'posted_date' => isset($dates[1][0]) ? DateParser::parse($dates[1][0]) : null,
            'closing_date' => isset($dates[1][1]) ? DateParser::parse($dates[1][1]) : null,
            'location' => $this->extractLocationFromText($text),
        ];
    }

    private function scrapeMirrorDetail(string $url): array
    {
        try {
            $markdown = $this->fetchMirrorMarkdown($url);
        } catch (\Throwable) {
            return [];
        }

        $institution = '';
        if (preg_match('/^##\s+(.+)$/m', $markdown, $matches) === 1) {
            $institution = trim($matches[1]);
        }

        $address = '';
        if (preg_match('/\*\*\s*Address\*\*\s*(.+)$/m', $markdown, $matches) === 1) {
            $address = trim($matches[1]);
        }

        $qualification = '';
        if (preg_match('/\*\*\s*Qualification\*\*\s*(.+)$/m', $markdown, $matches) === 1) {
            $qualification = trim($matches[1]);
        }

        $discipline = '';
        if (preg_match('/\*\*\s*Discipline\*\*\s*(.+)$/m', $markdown, $matches) === 1) {
            $discipline = trim($matches[1]);
        }

        $postedDate = null;
        $closingDate = null;
        preg_match_all('/([A-Z][a-z]{2,8}\s+\d{1,2},\s+\d{4})/', $markdown, $dates);
        if (($dates[1] ?? []) !== []) {
            $postedDate = DateParser::parse($dates[1][0]);
        }

        if (preg_match('/\*\*\s*Apply by\*\*\s*([A-Za-z]+\s+\d{1,2},\s+\d{4})/u', $markdown, $matches) === 1) {
            $closingDate = DateParser::parse($matches[1]);
        } elseif (isset($dates[1][1])) {
            $closingDate = DateParser::parse($dates[1][1]);
        }

        $description = $this->extractMirrorDescription($markdown);

        return array_filter([
            'institution' => $institution,
            'location' => $address,
            'description' => $description,
            'posted_date' => $postedDate,
            'closing_date' => $closingDate,
            'qualification' => $qualification,
            'discipline' => $discipline,
        ], static fn ($value): bool => $value !== null && trim((string) $value) !== '');
    }

    private function buildJobPayload(string $title, string $url, array $meta, array $detail, array $rawMeta = []): array
    {
        $institution = trim((string) ($detail['institution'] ?? $meta['institution'] ?? ''));
        $location = trim((string) ($detail['location'] ?? $meta['location'] ?? ''));
        $description = trim((string) ($detail['description'] ?? ''));

        if ($description === '') {
            $description = implode(' | ', array_filter([
                $institution,
                $location,
                $meta['age_text'] ?? null,
            ], static fn (?string $value): bool => trim((string) $value) !== ''));
            $description = preg_replace('/\[(.*?)\]\(([^)]+)\)/u', '$1', $description) ?? $description;
            $description = Str::slice(trim($description), 0, 3000);
        }

        return [
            'source' => $this->getSourceKey(),
            'title' => $title,
            'institution' => $institution,
            'location' => $location,
            'url' => $url,
            'description' => $description,
            'posted_date' => $detail['posted_date'] ?? ($meta['posted_date'] ?? null),
            'closing_date' => $detail['closing_date'] ?? ($meta['closing_date'] ?? null),
            'raw_meta' => array_filter(array_merge($rawMeta, [
                'qualification' => $detail['qualification'] ?? null,
                'discipline' => $detail['discipline'] ?? null,
            ]), static fn ($value): bool => $value !== null && trim((string) $value) !== ''),
        ];
    }

    private function extractMetaLines(string $text, string $title): array
    {
        $clean = trim(str_replace($title, '', $text));
        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/[\r\n]+/u', $clean) ?: []
        )));

        $institution = $lines[0] ?? '';
        $location = '';
        $postedDate = null;
        $closingDate = null;

        foreach ($lines as $line) {
            if ($location === '' && preg_match('/(Beijing|Shanghai|Hangzhou|Ningbo|Wuhan|Guangzhou|Shandong|Province|China)/iu', $line) === 1) {
                $location = $line;
            }

            if ($postedDate === null && preg_match('/\b(\d+\s+(?:minute|hour|day|week|month)s?\s+ago)\b/iu', $line, $matches) === 1) {
                $postedDate = DateParser::parse($matches[1]);
            }

            if ($closingDate === null && preg_match('/Apply by:\s*([A-Za-z]+\s+\d{1,2},\s+\d{4})/iu', $line, $matches) === 1) {
                $closingDate = DateParser::parse($matches[1]);
            }
        }

        return [
            'institution' => $institution,
            'location' => $location,
            'posted_date' => $postedDate,
            'closing_date' => $closingDate,
        ];
    }

    private function extractMirrorListingMeta(string $metaLine, string $ageLine): array
    {
        $institution = '';
        $locations = [];
        $closingDate = null;
        $postedDate = $this->parseMirrorAge($ageLine);

        if (preg_match('/^\[(.+?)\]\(https:\/\/www\.chinauniversityjobs\.com\/employer\//', $metaLine, $matches) === 1) {
            $institution = trim($matches[1], " \t\n\r\0\x0B_");
        }

        if (preg_match_all('/\[_([^]]+)_\]\(https:\/\/www\.chinauniversityjobs\.com\/job-location\//', $metaLine, $matches) >= 1) {
            $locations = array_values(array_filter(array_map(
                static fn (string $value): string => trim($value),
                $matches[1] ?? []
            )));
        }

        if (preg_match('/_Apply by:_\s*([A-Za-z]+\s+\d{1,2},\s+\d{4})/u', $metaLine, $matches) === 1) {
            $closingDate = DateParser::parse($matches[1]);
        }

        return [
            'institution' => $institution,
            'location' => implode(', ', $locations),
            'posted_date' => $postedDate,
            'closing_date' => $closingDate,
            'age_text' => trim($ageLine),
        ];
    }

    private function parseMirrorAge(string $line): ?string
    {
        if (preg_match('/(\d+)\s+(minute|hour|day|week|month|year)s?\s+ago/i', $line, $matches) !== 1) {
            return null;
        }

        $amount = (int) $matches[1];
        $unit = strtolower($matches[2]);
        $date = new \DateTimeImmutable('now', new \DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'UTC'));

        $interval = match ($unit) {
            'minute' => new \DateInterval('PT' . $amount . 'M'),
            'hour' => new \DateInterval('PT' . $amount . 'H'),
            'day' => new \DateInterval('P' . $amount . 'D'),
            'week' => new \DateInterval('P' . $amount . 'W'),
            'month' => new \DateInterval('P' . $amount . 'M'),
            'year' => new \DateInterval('P' . $amount . 'Y'),
            default => null,
        };

        return $interval instanceof \DateInterval ? $date->sub($interval)->format('Y-m-d') : null;
    }

    private function extractMirrorDescription(string $markdown): string
    {
        $section = $markdown;

        $jobOverviewPos = strpos($markdown, '### Job Overview');
        $moreInformationPos = strpos($markdown, '### More Information');
        $relatedJobsPos = strpos($markdown, '### Related Jobs');

        if ($jobOverviewPos !== false) {
            $section = substr($markdown, $jobOverviewPos);
        } elseif ($moreInformationPos !== false) {
            $section = substr($markdown, $moreInformationPos);
        }

        if ($relatedJobsPos !== false) {
            $section = substr($section, 0, max(0, $relatedJobsPos - ($jobOverviewPos !== false ? $jobOverviewPos : ($moreInformationPos !== false ? $moreInformationPos : 0))));
        }

        $section = preg_replace('/!\[[^\]]*]\([^)]+\)/u', '', $section) ?? $section;
        $section = preg_replace('/\[(.*?)\]\(([^)]+)\)/u', '$1', $section) ?? $section;
        $section = preg_replace('/\s+/u', ' ', $section) ?? $section;

        return Str::slice(trim($section), 0, 3000);
    }

    private function fetchMirrorMarkdown(string $url): string
    {
        $mirrorUrl = self::MIRROR_PREFIX . preg_replace('/^https?:\/\//i', '', $url);
        return $this->fetchHtml($mirrorUrl);
    }

    private function nextMeaningfulLine(array $lines, int $start): array
    {
        for ($index = $start; $index < count($lines); $index++) {
            $line = trim((string) $lines[$index]);
            if ($line === '') {
                continue;
            }

            return [
                'index' => $index,
                'line' => $line,
            ];
        }

        return [
            'index' => $start,
            'line' => '',
        ];
    }

    private function extractLocationFromText(string $text): string
    {
        if (preg_match('/(Beijing|Shanghai|Hangzhou|Ningbo|Wuhan|Guangzhou|Chengdu|China|Province)[^\.]{0,80}/iu', $text, $matches) === 1) {
            return trim($matches[0]);
        }

        return '';
    }

    private function containerText(\DOMNode $node, int $levels): string
    {
        $current = $node;
        for ($index = 0; $index < $levels; $index++) {
            if ($current->parentNode instanceof \DOMNode) {
                $current = $current->parentNode;
            }
        }

        return trim((string) $current->textContent);
    }
}
