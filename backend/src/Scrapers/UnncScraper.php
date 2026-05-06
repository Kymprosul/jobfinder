<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\DTO\ScrapeResult;
use App\Utils\DateParser;
use App\Utils\Str;
use Symfony\Component\DomCrawler\Crawler;

final class UnncScraper extends AbstractScraper
{
    public function getSourceKey(): string
    {
        return 'unnc';
    }

    public function scrape(array $config): ScrapeResult
    {
        $sourceConfig = $config['sources'][$this->getSourceKey()] ?? [];
        $maxResults = (int) ($sourceConfig['max_results'] ?? 20);

        try {
            $crawler = $this->fetchCrawler('https://jobs.nottingham.edu.cn/jobs-at-unnc/');
            $links = [];

            $crawler->filter('a[href*="/job/"]')->each(function (Crawler $node) use (&$links): void {
                $title = trim($node->text(''));
                $url = $this->absoluteUrl('https://jobs.nottingham.edu.cn/jobs-at-unnc/', $node->attr('href'));

                if ($title === '' || isset($links[$url])) {
                    return;
                }

                $links[$url] = [
                    'title' => $title,
                    'url' => $url,
                ];
            });

            $jobs = [];
            foreach (array_slice(array_values($links), 0, $maxResults) as $item) {
                $jobs[] = $this->scrapeDetail($item['url'], $item['title']);
            }

            return new ScrapeResult($this->getSourceKey(), count($jobs) > 0 ? 'ok' : 'empty', array_values(array_filter($jobs)));
        } catch (\Throwable $exception) {
            $status = $exception instanceof \App\Utils\BlockedSourceException ? 'blocked' : 'error';
            $this->logger->warning('UNNC no disponible', ['message' => $exception->getMessage()]);
            return new ScrapeResult($this->getSourceKey(), $status, [], $exception->getMessage());
        }
    }

    private function scrapeDetail(string $url, string $fallbackTitle): ?array
    {
        try {
            $crawler = $this->fetchCrawler($url);
        } catch (\Throwable $exception) {
            $this->logger->warning('No se pudo leer detalle UNNC', ['url' => $url, 'message' => $exception->getMessage()]);
            return [
                'source' => $this->getSourceKey(),
                'title' => $fallbackTitle,
                'institution' => 'University of Nottingham Ningbo China',
                'location' => 'Ningbo, China',
                'url' => $url,
                'description' => '',
                'posted_date' => null,
                'closing_date' => null,
                'raw_meta' => [],
            ];
        }

        $title = $this->textOrNull($crawler, 'h1, h2') ?? $fallbackTitle;
        $pageText = preg_replace('/\s+/u', ' ', trim($crawler->text('')));
        $department = $pageText ?: null;
        $location = $this->extractBetween((string) $pageText, 'Ningbo', 'Apply') ?? 'Ningbo, China';
        $closingDate = DateParser::parse($this->extractLabeledValue((string) $pageText, 'Closing time'));
        $description = $this->extractDescription($crawler);

        return [
            'source' => $this->getSourceKey(),
            'title' => $title,
            'institution' => $this->extractInstitution($department),
            'location' => trim($location) !== '' ? trim($location) : 'Ningbo, China',
            'url' => $url,
            'description' => $description,
            'posted_date' => null,
            'closing_date' => $closingDate,
            'raw_meta' => [
                'department' => $this->extractLabeledValue($crawler->text(), 'Job family'),
            ],
        ];
    }

    private function extractDescription(Crawler $crawler): string
    {
        $selectors = [
            '.job-desc',
            '.post-content.job-desc',
            '.post-content.content',
            '.post-job-block',
        ];

        foreach ($selectors as $selector) {
            if ($crawler->filter($selector)->count() === 0) {
                continue;
            }

            $text = preg_replace('/\s+/u', ' ', trim($crawler->filter($selector)->first()->text('')));
            if ($text !== null && $text !== '') {
                return Str::slice($text, 0, 2000);
            }
        }

        $bodyText = preg_replace('/\s+/u', ' ', trim($crawler->text('')));
        return Str::slice((string) $bodyText, 0, 2000);
    }

    private function extractInstitution(?string $text): string
    {
        if ($text === null) {
            return 'University of Nottingham Ningbo China';
        }

        if (preg_match('/School of [A-Za-z &]+|Centre for [A-Za-z &]+|Department of [A-Za-z,& ]+/u', $text, $matches) === 1) {
            return trim($matches[0]);
        }

        return 'University of Nottingham Ningbo China';
    }

    private function extractLabeledValue(string $text, string $label): ?string
    {
        if (preg_match('/' . preg_quote($label, '/') . '\s+([^\n\r]+)/iu', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractBetween(string $text, string $start, string $end): ?string
    {
        if (preg_match('/' . preg_quote($start, '/') . '(.*?)' . preg_quote($end, '/') . '/isu', $text, $matches) === 1) {
            return trim($start . ' ' . $matches[1]);
        }

        return null;
    }
}
