<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\DTO\ScrapeResult;
use App\Utils\DateParser;
use App\Utils\BlockedSourceException;
use App\Utils\HttpException;
use App\Utils\Str;
use Symfony\Component\DomCrawler\Crawler;

final class JoobleScraper extends AbstractScraper
{
    private const API_ENDPOINT_PREFIX = 'https://jooble.org/api/';

    public function getSourceKey(): string
    {
        return 'jooble';
    }

    public function scrape(array $config): ScrapeResult
    {
        $sourceConfig = $config['sources'][$this->getSourceKey()] ?? [];
        $maxPages = max(1, (int) ($sourceConfig['max_pages'] ?? 1));
        $maxResults = max(1, (int) ($sourceConfig['max_results'] ?? 40));
        $apiKey = trim((string) ($_ENV['JOOBLE_API_KEY'] ?? ''));
        $apiLocation = trim((string) ($sourceConfig['api_location'] ?? 'China'));
        $searchUrlTemplate = trim((string) ($sourceConfig['search_url'] ?? 'https://jooble.org/jobs-spanish-teaching/China'));

        $searchPlans = $this->buildKeywordPlans($config, [
            'spanish' => ['spanish'],
            'business' => ['business', 'international business', 'management', 'trade', 'commerce', 'e-commerce'],
        ], 1, 8);

        $jobs = [];
        $seenUrls = [];
        $categoryCounts = $this->initializeCategoryCounts($searchPlans);
        $fallbackBlocked = false;

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

                if ($apiKey !== '') {
                    $planJobs = $this->scrapeApiPages($apiKey, $apiLocation, $keyword, $category, $maxPages, $planCapacity);
                } else {
                    try {
                        $planJobs = $this->scrapePublicPages(
                            $searchUrlTemplate,
                            $apiLocation,
                            $keyword,
                            $category,
                            $maxPages,
                            $planCapacity
                        );
                    } catch (BlockedSourceException) {
                        $fallbackBlocked = true;
                        continue;
                    }
                }

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
            $this->logger->warning('Jooble no disponible', ['message' => $exception->getMessage()]);

            return new ScrapeResult($this->getSourceKey(), $status, [], $exception->getMessage());
        }

        if ($jobs === [] && $apiKey === '' && $fallbackBlocked) {
            return new ScrapeResult(
                $this->getSourceKey(),
                'disabled',
                [],
                'Fuente desactivada: Jooble bloquea la vista pública (Cloudflare/CAPTCHA) sin JOOBLE_API_KEY.'
            );
        }

        return new ScrapeResult($this->getSourceKey(), $jobs === [] ? 'empty' : 'ok', $jobs);
    }

    private function scrapeApiPages(
        string $apiKey,
        string $location,
        string $keyword,
        string $category,
        int $maxPages,
        int $maxResults
    ): array {
        $jobs = [];
        $seenUrls = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $apiJobs = $this->requestJobsFromApi($apiKey, $location, $keyword, $page);
            if ($apiJobs === []) {
                break;
            }

            $pageJobs = $this->mapApiJobs($apiJobs, $location, $keyword, $category);

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

    private function requestJobsFromApi(string $apiKey, string $location, string $keyword, int $page): array
    {
        $endpoint = self::API_ENDPOINT_PREFIX . rawurlencode($apiKey);

        try {
            $response = $this->client->post($endpoint, [
                'json' => [
                    'keywords' => $keyword,
                    'location' => $location,
                    'page' => max(1, $page),
                ],
            ]);
        } catch (\Throwable $exception) {
            throw new HttpException($exception->getMessage(), 0, $exception);
        }

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();

        if (in_array($statusCode, [401, 403], true)) {
            throw new HttpException('Acceso denegado por Jooble API (clave inválida o permisos insuficientes).');
        }

        if ($statusCode >= 400 || trim($body) === '') {
            throw new HttpException(sprintf('Jooble API devolvió una respuesta no usable [%s]', $statusCode));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new HttpException('Jooble API devolvió JSON no válido.');
        }

        $jobs = $decoded['jobs'] ?? [];

        return is_array($jobs) ? $jobs : [];
    }

    private function mapApiJobs(array $apiJobs, string $location, string $keyword, string $category): array
    {
        $jobs = [];

        foreach ($apiJobs as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $url = trim((string) ($item['link'] ?? $item['url'] ?? ''));
            if ($title === '' || $url === '') {
                continue;
            }

            $jobLocation = trim((string) ($item['location'] ?? $location));
            $company = trim((string) ($item['company'] ?? $item['companyName'] ?? ''));
            $snippet = trim((string) ($item['snippet'] ?? $item['description'] ?? ''));
            $postedDate = DateParser::parse((string) ($item['updated'] ?? $item['updatedAt'] ?? ''));

            $description = implode(' | ', array_filter([
                $snippet,
                $company !== '' ? 'Empresa: ' . $company : '',
                $jobLocation !== '' ? 'Ubicación: ' . $jobLocation : '',
                trim((string) ($item['salary'] ?? '')) !== '' ? 'Salario: ' . trim((string) $item['salary']) : '',
            ], static fn (string $value): bool => $value !== ''));

            $jobs[] = [
                'source' => $this->getSourceKey(),
                'title' => $title,
                'institution' => $company,
                'location' => $jobLocation,
                'url' => $url,
                'description' => Str::slice($description !== '' ? $description : $title, 0, 1800),
                'posted_date' => $postedDate,
                'closing_date' => null,
                'raw_meta' => array_filter([
                    'source_url' => 'https://jooble.org/jobs-' . $this->slugify($keyword) . '/' . rawurlencode($location),
                    'search_keyword' => $keyword,
                    'search_category' => $category,
                    'salary' => trim((string) ($item['salary'] ?? '')),
                    'type' => trim((string) ($item['type'] ?? '')),
                ], static fn ($value): bool => $value !== null && trim((string) $value) !== ''),
            ];
        }

        return $jobs;
    }

    private function scrapePublicPages(
        string $searchUrlTemplate,
        string $location,
        string $keyword,
        string $category,
        int $maxPages,
        int $maxResults
    ): array {
        $jobs = [];
        $seenUrls = [];
        $baseSearchUrl = $this->buildSearchUrl($searchUrlTemplate, $keyword, $location);

        for ($page = 1; $page <= $maxPages; $page++) {
            $pageUrl = $this->buildPageUrl($baseSearchUrl, $page);
            $html = $this->fetchHtml($pageUrl);
            $pageJobs = $this->parseListingHtml($html, $pageUrl, $location, $keyword, $category, $maxResults - count($jobs));

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

    private function parseListingHtml(
        string $html,
        string $sourceUrl,
        string $defaultLocation,
        string $keyword,
        string $category,
        int $remaining
    ): array {
        $jobs = [];
        $crawler = new Crawler($html, $sourceUrl);
        $links = $crawler->filter('a.job_card_link');

        foreach ($links as $node) {
            if (count($jobs) >= $remaining) {
                break;
            }

            $linkCrawler = new Crawler($node, $sourceUrl);
            $title = trim($linkCrawler->text(''));
            $url = $this->absoluteUrl($sourceUrl, $linkCrawler->attr('href'));

            if ($title === '' || $url === '') {
                continue;
            }

            $cardText = preg_replace('/\s+/u', ' ', $this->containerText($node, 5)) ?? $title;
            $postedDate = $this->extractRelativeDate($cardText);
            $location = $this->extractLocation($cardText, $defaultLocation);

            $jobs[] = [
                'source' => $this->getSourceKey(),
                'title' => $title,
                'institution' => '',
                'location' => $location,
                'url' => $url,
                'description' => Str::slice(trim($cardText) !== '' ? trim($cardText) : $title, 0, 1800),
                'posted_date' => $postedDate,
                'closing_date' => null,
                'raw_meta' => array_filter([
                    'source_url' => $sourceUrl,
                    'search_keyword' => $keyword,
                    'search_category' => $category,
                ], static fn ($value): bool => $value !== null && trim((string) $value) !== ''),
            ];
        }

        return $jobs;
    }

    private function buildSearchUrl(string $template, string $keyword, string $location): string
    {
        $template = trim($template);
        if ($template === '') {
            return 'https://jooble.org/jobs-' . $this->slugify($keyword) . '/' . rawurlencode($location);
        }

        if (str_contains($template, '{query}')) {
            return str_replace('{query}', $this->slugify($keyword), $template);
        }

        if (preg_match('/\/jobs-[^\/?#]+\/[^\/?#]+/i', $template) === 1) {
            return preg_replace(
                '/\/jobs-[^\/?#]+\/[^\/?#]+/i',
                '/jobs-' . $this->slugify($keyword) . '/' . rawurlencode($location),
                $template
            ) ?? $template;
        }

        return $template;
    }

    private function buildPageUrl(string $baseUrl, int $page): string
    {
        if ($page <= 1) {
            return $baseUrl;
        }

        return $this->replaceQueryParameter($baseUrl, 'p', (string) $page);
    }

    private function extractRelativeDate(string $text): ?string
    {
        if (preg_match('/(\d+\s+(?:minute|hour|day|week|month|year)s?\s+ago)/iu', $text, $matches) !== 1) {
            return null;
        }

        return DateParser::parse($matches[1]);
    }

    private function extractLocation(string $text, string $defaultLocation): string
    {
        if (preg_match('/\b(China|Beijing|Shanghai|Shenzhen|Guangzhou|Hangzhou|Nanjing|Wuhan|Chengdu|Online)\b/iu', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return $defaultLocation;
    }

    private function slugify(string $value): string
    {
        $normalized = Str::lower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? $normalized;
        $normalized = trim($normalized, '-');

        return $normalized !== '' ? $normalized : 'jobs';
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
