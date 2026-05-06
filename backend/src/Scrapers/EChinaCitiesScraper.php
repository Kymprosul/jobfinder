<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\DTO\ScrapeResult;
use App\Utils\DateParser;
use App\Utils\Str;
use App\Utils\TextNormalizer;
use Symfony\Component\DomCrawler\Crawler;

final class EChinaCitiesScraper extends AbstractScraper
{
    private const RESULTS_PER_PAGE = 25;

    public function getSourceKey(): string
    {
        return 'echinacities';
    }

    public function scrape(array $config): ScrapeResult
    {
        $sourceConfig = $config['sources'][$this->getSourceKey()] ?? [];
        $maxResults = (int) ($sourceConfig['max_results'] ?? 60);
        $maxPages = max(1, (int) ($sourceConfig['max_pages'] ?? 3));
        $keywords = $this->buildSearchPlans($config);

        $jobs = [];
        $seenIds = [];
        $hadUsableSearch = false;

        try {
            foreach ($keywords as $plan) {
                if (count($jobs) >= $maxResults) {
                    break;
                }

                $parsed = $this->searchKeyword($plan, $config, $maxPages, $maxResults - count($jobs));
                if ($parsed !== []) {
                    $hadUsableSearch = true;
                }

                foreach ($parsed as $job) {
                    if (count($jobs) >= $maxResults) {
                        break 2;
                    }

                    $id = (string) ($job['raw_meta']['echina_id'] ?? $job['url']);
                    if (isset($seenIds[$id])) {
                        continue;
                    }

                    $seenIds[$id] = true;
                    $jobs[] = $job;
                }
            }

            if ($jobs !== []) {
                return new ScrapeResult($this->getSourceKey(), 'ok', $jobs);
            }

            if ($hadUsableSearch) {
                return new ScrapeResult($this->getSourceKey(), 'empty', []);
            }

            return new ScrapeResult(
                $this->getSourceKey(),
                'blocked',
                [],
                'La fuente no ofreció resultados de búsqueda utilizables sin interacción adicional.'
            );
        } catch (\Throwable $exception) {
            $status = $exception instanceof \App\Utils\BlockedSourceException ? 'blocked' : 'error';
            $this->logger->warning('eChinacities no disponible', ['message' => $exception->getMessage()]);
            return new ScrapeResult($this->getSourceKey(), $status, [], $exception->getMessage());
        }
    }

    private function buildSearchPlans(array $config): array
    {
        return $this->buildKeywordPlans($config, [
            'spanish' => ['spanish'],
            'business' => ['business', 'international business', 'management', 'trade', 'commerce', 'e-commerce'],
        ], 5, 14);
    }

    private function searchKeyword(array $plan, array $config, int $maxPages, int $remainingCapacity): array
    {
        $keyword = (string) ($plan['keyword'] ?? '');
        $category = (string) ($plan['category'] ?? '');
        $jobs = [];
        $seenPageIds = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $query = ['keyword' => $keyword];
            if ($page > 1) {
                $query['page'] = (string) $page;
            }
            $url = 'https://jobs.echinacities.com/jobs/search?' . http_build_query($query);

            try {
                $html = $this->fetchHtml($url);
            } catch (\Throwable) {
                break;
            }

            $payload = $this->extractSearchPayload($html);
            if ($payload === null || !isset($payload['data']['list']) || !is_array($payload['data']['list'])) {
                break;
            }

            $pageAdded = 0;

            foreach ($payload['data']['list'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $title = trim((string) ($item['title'] ?? ''));
                $description = trim((string) ($item['description'] ?? ''));
                $institution = trim((string) ($item['company_name'] ?? ''));
                $jobId = (string) ($item['id'] ?? '');

                if ($title === '' || !$this->isLikelyJobTitle($title)) {
                    continue;
                }

                if (!$this->matchesSearchIntent($category, $keyword, $title, $description, $institution)) {
                    continue;
                }

                if ($jobId !== '' && isset($seenPageIds[$jobId])) {
                    continue;
                }

                if ($jobId !== '') {
                    $seenPageIds[$jobId] = true;
                }

                $jobs[] = [
                    'source' => $this->getSourceKey(),
                    'title' => $title,
                    'institution' => $institution,
                    'location' => trim((string) ($item['city'] ?? '')),
                    'url' => sprintf('https://jobs.echinacities.com/jobchapter/%s', (string) ($item['id'] ?? '')),
                    'description' => Str::slice($description, 0, 1200),
                    'posted_date' => DateParser::parse((string) ($item['refresh_time'] ?? '')),
                    'closing_date' => DateParser::parse((string) ($item['end_time'] ?? '')),
                    'raw_meta' => [
                        'search_keyword' => $keyword,
                        'search_category' => $category,
                        'echina_id' => $item['id'] ?? null,
                        'job_type' => $item['job_type'] ?? null,
                        'salary' => $item['salary'] ?? null,
                        'detail_fetched' => false,
                        'skills' => $item['skills'] ?? null,
                        'tags' => $item['sktag'] ?? [],
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
                $pageAdded++;

                if (count($jobs) >= $remainingCapacity) {
                    break 2;
                }
            }

            if ($pageAdded === 0) {
                break;
            }
        }

        return $jobs;
    }

    private function extractSearchPayload(string $html): ?array
    {
        if (preg_match('/var\s+_searchJobList\s*=\s*(\{.*?\})\s*;/su', $html, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode($matches[1], true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function isLikelyJobTitle(string $title): bool
    {
        $normalized = TextNormalizer::normalize($title);

        $blockedPatterns = [
            'can i get a job',
            'teaching other languages in china',
            'how to',
            'tips for',
            'visa',
            'guide',
            'forum',
            'voice actor',
        ];

        foreach ($blockedPatterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return false;
            }
        }

        return true;
    }

    private function matchesSearchIntent(
        string $category,
        string $keyword,
        string $title,
        string $description,
        string $institution
    ): bool {
        $titleNormalized = TextNormalizer::normalize($title);
        $descriptionNormalized = TextNormalizer::normalize($description);
        $institutionNormalized = TextNormalizer::normalize($institution);
        $haystack = implode(' ', [$titleNormalized, $descriptionNormalized, $institutionNormalized]);

        if ($category === 'spanish') {
            $spanishSignals = ['spanish', 'hispanic', 'iberian', 'romance language'];

            foreach ($spanishSignals as $signal) {
                if (str_contains($haystack, $signal)) {
                    return true;
                }
            }

            return false;
        }

        if ($category === 'business') {
            $businessSignals = [
                'international business',
                'business teacher',
                'business lecturer',
                'business professor',
                'school of business',
                'business faculty',
            ];
            $roleSignals = ['lecturer', 'professor', 'teacher', 'instructor', 'faculty', 'teaching fellow', 'guest lecturer'];
            $institutionSignals = ['university', 'college', 'faculty', 'school', 'department', 'academy'];

            $hasBusinessSignal = $this->containsAny($haystack, $businessSignals);
            $hasRoleSignal = $this->containsAny($haystack, $roleSignals);
            $hasInstitutionSignal = $this->containsAny($haystack, $institutionSignals);

            if ($hasBusinessSignal && $hasRoleSignal && $hasInstitutionSignal) {
                return true;
            }

            $keywordNormalized = TextNormalizer::normalize($keyword);
            if ($keywordNormalized !== '' && str_contains($haystack, $keywordNormalized)) {
                return true;
            }
        }

        $keywordNormalized = TextNormalizer::normalize($keyword);

        return $keywordNormalized !== '' && str_contains($haystack, $keywordNormalized);
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
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
            '.job_detail',
            '.job-description',
            '.description',
            '.job-content',
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
