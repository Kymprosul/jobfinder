<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\DTO\ScrapeResult;
use App\Utils\DateParser;
use App\Utils\Str;
use App\Utils\TextNormalizer;
use Symfony\Component\DomCrawler\Crawler;

final class JobsCinaScraper extends AbstractScraper
{
    public function getSourceKey(): string
    {
        return 'jobscina';
    }

    public function scrape(array $config): ScrapeResult
    {
        $sourceConfig = $config['sources'][$this->getSourceKey()] ?? [];
        $maxPages = max(1, (int) ($sourceConfig['max_pages'] ?? 2));
        $maxResults = max(1, (int) ($sourceConfig['max_results'] ?? 40));
        $searchUrlTemplate = trim((string) ($sourceConfig['search_url'] ?? 'https://jobscina.com/jobs/list?s=&l='));
        $searchPlans = $this->buildKeywordPlans($config, [
            'spanish' => ['spanish'],
            'business' => ['business', 'international business', 'management', 'trade', 'commerce', 'e-commerce'],
        ], 2, 8);

        $jobs = [];
        $seenIds = [];
        $categoryCounts = $this->initializeCategoryCounts($searchPlans);

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

                $searchUrl = $this->replaceQueryParameter($searchUrlTemplate, 's', (string) $plan['keyword']);
                $planAdded = 0;

                for ($page = 1; $page <= $maxPages; $page++) {
                    $pageUrl = $this->buildPageUrl($searchUrl, $page);
                    $html = $this->fetchHtml($pageUrl);
                    $payload = $this->extractNextData($html);
                    $items = $payload['props']['pageProps']['resp']['info']['list'] ?? null;

                    if (!is_array($items) || $items === []) {
                        break;
                    }

                    $pageAdded = 0;

                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $jobId = trim((string) ($item['id'] ?? ''));
                        $title = trim((string) ($item['title'] ?? ''));
                        $url = $this->normalizeJobUrl((string) ($item['url'] ?? ''));

                        if ($title === '' || $url === '') {
                            continue;
                        }

                        $seenKey = $jobId !== '' ? $jobId : $url;
                        if (isset($seenIds[$seenKey])) {
                            continue;
                        }

                        $seenIds[$seenKey] = true;
                        $pageAdded++;
                        $planAdded++;
                        $categoryCounts[(string) $plan['category']]++;
                        $jobs[] = [
                            'source' => $this->getSourceKey(),
                            'title' => $title,
                            'institution' => trim((string) ($item['company_name'] ?? '')),
                            'location' => trim((string) ($item['city'] ?? '')),
                            'url' => $url,
                            'description' => Str::slice(trim((string) ($item['description'] ?? '')), 0, 1800),
                            'posted_date' => DateParser::parse((string) ($item['update_time'] ?? '')),
                            'closing_date' => null,
                            'raw_meta' => [
                                'jobscina_id' => $jobId !== '' ? $jobId : null,
                                'original_site' => trim((string) ($item['site'] ?? '')),
                                'source_url' => $pageUrl,
                                'search_keyword' => $plan['keyword'],
                                'search_category' => $plan['category'],
                                'detail_fetched' => false,
                            ],
                        ];

                        $jobIndex = count($jobs) - 1;
                        if (isset($jobs[$jobIndex])) {
                            // No pre-filter score is available at scraper stage yet; fetch all details for now and limit later if needed.
                            $detail = $this->fetchDetailData((string) ($jobs[$jobIndex]['url'] ?? ''));
                            if ($detail !== null) {
                                $jobs[$jobIndex] = $this->mergeDetail($jobs[$jobIndex], $detail);
                            }
                        }

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

            return new ScrapeResult($this->getSourceKey(), $jobs === [] ? 'empty' : 'ok', $jobs);
        } catch (\Throwable $exception) {
            $status = $exception instanceof \App\Utils\BlockedSourceException ? 'blocked' : 'error';
            $this->logger->warning('JobsCina no disponible', ['message' => $exception->getMessage()]);
            return new ScrapeResult($this->getSourceKey(), $status, [], $exception->getMessage());
        }
    }

    private function buildPageUrl(string $baseUrl, int $page): string
    {
        if ($page <= 1) {
            return $baseUrl;
        }

        $parts = parse_url($baseUrl);
        if ($parts === false) {
            return $baseUrl;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['page'] = (string) $page;

        $path = $parts['path'] ?? '/jobs/list';
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'jobscina.com';

        return sprintf('%s://%s%s?%s', $scheme, $host, $path, http_build_query($query));
    }

    private function extractNextData(string $html): array
    {
        if (preg_match('/__NEXT_DATA__\s*=\s*(\{.*?\})\s*;__NEXT_LOADED_PAGES__/su', $html, $matches) !== 1) {
            throw new \RuntimeException('JobsCina no expuso __NEXT_DATA__ utilizable.');
        }

        $decoded = json_decode($matches[1], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('JobsCina devolvió JSON no válido.');
        }

        return $decoded;
    }

    private function normalizeJobUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, 'http://')) {
            return 'https://' . substr($url, strlen('http://'));
        }

        if (str_starts_with($url, 'https://')) {
            return $url;
        }

        return $this->absoluteUrl('https://jobscina.com/jobs/list', $url);
    }

    private function fetchDetailData(string $detailUrl): ?array
    {
        $detailUrl = trim($detailUrl);
        if ($detailUrl === '') {
            return null;
        }

        try {
            $html = $this->fetchHtml($detailUrl);
        } catch (\Throwable) {
            return null;
        }

        $crawler = new Crawler($html, $detailUrl);
        $pageText = $this->normalizeText($crawler->text(''));

        $description = '';
        $descriptionSelectors = [
            '.job-content',
            '.job-detail',
            '.job-desc',
            '.description',
            '.content',
            '[class*="description"]',
        ];

        foreach ($descriptionSelectors as $selector) {
            if ($crawler->filter($selector)->count() === 0) {
                continue;
            }

            $candidate = $this->normalizeText($crawler->filter($selector)->first()->text(''));
            if (mb_strlen($candidate) > mb_strlen($description)) {
                $description = $candidate;
            }
        }

        if ($description === '' && preg_match('/"description"\s*:\s*"([^"]{120,})"/u', $html, $matches) === 1) {
            $description = $this->normalizeText(html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5));
        }

        if ($description === '') {
            $description = Str::slice($pageText, 0, 4000);
        } else {
            $description = Str::slice($description, 0, 4000);
        }

        $salary = null;
        if (preg_match('/(?:salary|salary range|compensation|薪资|工资)\s*[:：]?\s*([^\n\r|]{2,120})/iu', $pageText, $matches) === 1) {
            $salary = trim($matches[1]);
        }

        $requirements = null;
        if (preg_match('/(?:requirements?|qualifications?|job requirements?|任职要求)\s*[:：]?\s*([^\n\r]{20,800})/iu', $pageText, $matches) === 1) {
            $requirements = trim($matches[1]);
        }

        $institutionType = null;
        if (preg_match('/(?:institution type|company type|employer type|公司性质)\s*[:：]?\s*([^\n\r|]{2,120})/iu', $pageText, $matches) === 1) {
            $institutionType = trim($matches[1]);
        }

        return [
            'description' => $description,
            'salary' => $salary,
            'requirements' => $requirements,
            'institution_type' => $institutionType,
        ];
    }

    private function mergeDetail(array $job, array $detail): array
    {
        $listingDescription = trim((string) ($job['description'] ?? ''));
        $detailDescription = trim((string) ($detail['description'] ?? ''));

        if ($detailDescription !== '' && mb_strlen($detailDescription) > mb_strlen($listingDescription)) {
            $job['description'] = $detailDescription;
        }

        $requirements = trim((string) ($detail['requirements'] ?? ''));
        if ($requirements !== '') {
            $job['raw_meta']['requirements'] = $requirements;
            $currentDescription = trim((string) ($job['description'] ?? ''));
            if ($currentDescription !== '' && !str_contains(TextNormalizer::normalize($currentDescription), TextNormalizer::normalize($requirements))) {
                $job['description'] = Str::slice($currentDescription . "\n\nRequirements:\n" . $requirements, 0, 4000);
            }
        }

        $salary = trim((string) ($detail['salary'] ?? ''));
        if ($salary !== '') {
            $job['raw_meta']['salary'] = $salary;
        }

        $institutionType = trim((string) ($detail['institution_type'] ?? ''));
        if ($institutionType !== '') {
            $job['raw_meta']['institution_type'] = $institutionType;
        }

        $job['raw_meta']['detail_fetched'] = true;

        return $job;
    }

    private function normalizeText(?string $value): string
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5);
        $value = preg_replace('/\x{200B}|\x{FEFF}/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B\x{00A0}");
    }
}
