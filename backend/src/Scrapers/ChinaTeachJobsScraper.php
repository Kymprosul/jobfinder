<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\DTO\ScrapeResult;
use App\Utils\DateParser;
use App\Utils\Str;

final class ChinaTeachJobsScraper extends AbstractScraper
{
    private const MIRROR_PREFIX = 'https://r.jina.ai/http://';

    public function getSourceKey(): string
    {
        return 'chinateachjobs';
    }

    public function scrape(array $config): ScrapeResult
    {
        $sourceConfig = $config['sources'][$this->getSourceKey()] ?? [];
        $maxPages = max(1, (int) ($sourceConfig['max_pages'] ?? 2));
        $maxResults = max(1, (int) ($sourceConfig['max_results'] ?? 40));
        $searchUrlTemplate = trim((string) ($sourceConfig['search_url'] ?? 'https://www.chinateachjobs.com/jobs/?s=spanish&category='));
        $searchPlans = $this->buildKeywordPlans($config, [
            'spanish' => ['spanish'],
            'business' => ['business', 'international business', 'management', 'trade', 'commerce', 'e-commerce'],
        ], 1, 4);

        $jobs = [];
        $seenUrls = [];
        $categoryCounts = $this->initializeCategoryCounts($searchPlans);

        try {
            foreach ($searchPlans as $plan) {
                if (count($jobs) >= $maxResults) {
                    break;
                }

                $category = (string) ($plan['category'] ?? '');
                $keyword = (string) ($plan['keyword'] ?? '');
                $planCapacity = $this->computeCategoryPlanCapacity($categoryCounts, $category, $maxResults, count($jobs));

                if ($category === '' || $keyword === '' || $planCapacity <= 0) {
                    continue;
                }

                $searchUrl = $this->replaceQueryParameter($searchUrlTemplate, 's', $keyword);
                $planJobs = $this->scrapeMirrorSearch($searchUrl, $maxPages, $category, $keyword, $planCapacity);

                foreach ($planJobs as $job) {
                    $url = trim((string) ($job['url'] ?? ''));
                    if ($url === '' || isset($seenUrls[$url])) {
                        continue;
                    }

                    $seenUrls[$url] = true;
                    $jobs[] = $job;
                    $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;

                    if (count($jobs) >= $maxResults) {
                        break 2;
                    }
                }
            }
        } catch (\Throwable $exception) {
            $status = $exception instanceof \App\Utils\BlockedSourceException ? 'blocked' : 'error';
            $this->logger->warning('ChinaTeachJobs no disponible', ['message' => $exception->getMessage()]);

            return new ScrapeResult($this->getSourceKey(), $status, [], $exception->getMessage());
        }

        return new ScrapeResult($this->getSourceKey(), $jobs === [] ? 'empty' : 'ok', $jobs);
    }

    private function scrapeMirrorSearch(
        string $searchUrl,
        int $maxPages,
        string $category,
        string $keyword,
        int $maxResults
    ): array {
        $jobs = [];
        $seenUrls = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $pageUrl = $this->buildPageUrl($searchUrl, $page);
            $markdown = $this->fetchMirrorMarkdown($pageUrl);
            $pageJobs = $this->parseMirrorListings($markdown, $category, $keyword, $pageUrl, $maxResults - count($jobs));

            if ($pageJobs === []) {
                break;
            }

            $addedOnPage = 0;
            foreach ($pageJobs as $job) {
                $url = trim((string) ($job['url'] ?? ''));
                if ($url === '' || isset($seenUrls[$url])) {
                    continue;
                }

                $seenUrls[$url] = true;
                $jobs[] = $job;
                $addedOnPage++;

                if (count($jobs) >= $maxResults) {
                    break 2;
                }
            }

            if ($addedOnPage === 0) {
                break;
            }
        }

        return $jobs;
    }

    private function buildPageUrl(string $baseUrl, int $page): string
    {
        if ($page <= 1) {
            return $baseUrl;
        }

        return $this->replaceQueryParameter($baseUrl, 'page', (string) $page);
    }

    private function parseMirrorListings(
        string $markdown,
        string $category,
        string $keyword,
        string $sourceUrl,
        int $remaining
    ): array {
        $jobs = [];
        $lines = preg_split('/\R/u', $markdown) ?: [];

        for ($index = 0; $index < count($lines); $index++) {
            if (count($jobs) >= $remaining) {
                break;
            }

            $line = trim((string) $lines[$index]);
            if (preg_match('/^### \[(.+?)\]\((https:\/\/www\.chinateachjobs\.com\/jobs\/[^ )]+)/u', $line, $matches) !== 1) {
                continue;
            }

            $title = trim($matches[1]);
            $url = trim($matches[2]);
            if ($title === '' || $url === '') {
                continue;
            }

            $metaLine = $this->nextMeaningfulLine($lines, $index + 1);
            $ageLine = $this->nextMeaningfulLine($lines, ($metaLine['index'] ?? $index) + 1);
            $meta = $this->extractListingMeta(
                (string) ($metaLine['line'] ?? ''),
                (string) ($ageLine['line'] ?? '')
            );

            $description = implode(' | ', array_filter([
                $title,
                $meta['institution'],
                $meta['location'],
                $meta['age_text'],
            ], static fn (?string $value): bool => trim((string) $value) !== ''));

            $jobs[] = [
                'source' => $this->getSourceKey(),
                'title' => $title,
                'institution' => $meta['institution'],
                'location' => $meta['location'],
                'url' => $url,
                'description' => Str::slice(trim($description), 0, 1800),
                'posted_date' => $meta['posted_date'],
                'closing_date' => null,
                'raw_meta' => array_filter([
                    'source_url' => $sourceUrl,
                    'search_keyword' => $keyword,
                    'search_category' => $category,
                    'listing_meta_line' => $meta['meta_line'],
                    'listing_age_line' => $meta['age_text'],
                ], static fn ($value): bool => $value !== null && trim((string) $value) !== ''),
            ];
        }

        return $jobs;
    }

    private function extractListingMeta(string $metaLine, string $ageLine): array
    {
        $institution = '';
        if (preg_match('/^\[([^\]]+)\]\(https:\/\/www\.chinateachjobs\.com\/employer\/[^)]+\)/u', $metaLine, $matches) === 1) {
            $institution = trim($matches[1]);
        }

        $locations = [];
        if (preg_match_all('/\[_([^]]+)_\]\(https:\/\/www\.chinateachjobs\.com\/job-location\/[^)]+\)/u', $metaLine, $matches) >= 1) {
            $locations = array_values(array_filter(array_map(
                static fn (string $value): string => trim($value),
                $matches[1] ?? []
            )));
        }

        $postedDate = null;
        if (preg_match('/(\d+\s+(?:minute|hour|day|week|month|year)s?\s+ago)/iu', $ageLine, $matches) === 1) {
            $postedDate = DateParser::parse($matches[1]);
        }

        return [
            'institution' => $institution,
            'location' => implode(', ', $locations),
            'posted_date' => $postedDate,
            'meta_line' => trim($metaLine),
            'age_text' => trim($ageLine),
        ];
    }

    private function fetchMirrorMarkdown(string $url): string
    {
        usleep(300000);
        $mirrorUrl = self::MIRROR_PREFIX . preg_replace('/^https?:\/\//i', '', $url);

        try {
            return $this->fetchHtml($mirrorUrl);
        } catch (\App\Utils\BlockedSourceException $exception) {
            $isRateLimited = str_contains($exception->getMessage(), '[429]');
            if (!$isRateLimited) {
                throw $exception;
            }

            usleep(1200000);

            return $this->fetchHtml($mirrorUrl);
        }
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
}
