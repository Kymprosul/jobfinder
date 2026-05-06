<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\DTO\ScrapeResult;
use App\Utils\DateParser;
use App\Utils\HttpException;
use App\Utils\Str;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class HiredChinaScraper extends AbstractScraper
{
    public function getSourceKey(): string
    {
        return 'hiredchina';
    }

    public function scrape(array $config): ScrapeResult
    {
        $sourceConfig = $config['sources'][$this->getSourceKey()] ?? [];
        $maxPages = max(1, (int) ($sourceConfig['max_pages'] ?? 1));
        $maxResults = max(1, (int) ($sourceConfig['max_results'] ?? 40));
        $legacyKeyword = trim((string) ($sourceConfig['search_keyword'] ?? ''));
        $limit = max(1, min(30, (int) ($sourceConfig['api_limit'] ?? 30)));
        $clientId = max(1, (int) ($sourceConfig['client_id'] ?? 2));
        $cookie = trim((string) ($_ENV['HIREDCHINA_COOKIE'] ?? ''));
        $searchPlans = $this->buildKeywordPlans($config, [
            'spanish' => ['spanish'],
            'business' => ['business', 'international business', 'management', 'trade', 'commerce', 'e-commerce'],
        ], 2, 8);

        if ($legacyKeyword !== '') {
            array_unshift($searchPlans, [
                'category' => (string) ($searchPlans[0]['category'] ?? ($this->configuredSearchKeys($config, ['spanish'])[0] ?? 'spanish')),
                'keyword' => $legacyKeyword,
            ]);
        }

        $searchPlans = $this->uniqueKeywordPlans($searchPlans, 10);

        if ($cookie === '') {
            return new ScrapeResult(
                $this->getSourceKey(),
                'disabled',
                [],
                'Fuente desactivada: falta HIREDCHINA_COOKIE en backend/.env.'
            );
        }

        $jobs = [];
        $seen = [];
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

                $planAdded = 0;
                for ($page = 1; $page <= $maxPages; $page++) {
                    $payload = $this->fetchApiPage($page, $limit, (string) $plan['keyword'], $clientId, $cookie);
                    $items = $payload['data']['list'] ?? null;

                    if (!is_array($items) || $items === []) {
                        break;
                    }

                    $pageAdded = 0;

                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $jobId = (int) ($item['id'] ?? 0);
                        $line = trim((string) ($item['line'] ?? ''));
                        $title = trim((string) ($item['title'] ?? ''));
                        $url = $line !== ''
                            ? 'https://www.hiredchina.com/jobs/' . $line
                            : 'https://www.hiredchina.com/jobs';

                        if ($jobId <= 0 || $title === '' || isset($seen[$jobId])) {
                            continue;
                        }

                        $seen[$jobId] = true;
                        $pageAdded++;
                        $planAdded++;
                        $categoryCounts[(string) $plan['category']]++;
                        $jobs[] = [
                            'source' => $this->getSourceKey(),
                            'title' => $title,
                            'institution' => trim((string) ($item['companyName'] ?? '')),
                            'location' => $this->extractLocation($item),
                            'url' => $url,
                            'description' => $this->buildDescription($item),
                            'posted_date' => DateParser::parse((string) ($item['refreshAt'] ?? $item['createdAt'] ?? '')),
                            'closing_date' => null,
                            'raw_meta' => [
                                'hiredchina_id' => $jobId,
                                'company_id' => $item['companyId'] ?? null,
                                'company_line' => $item['companyLine'] ?? null,
                                'salary_key' => $item['salaryKey'] ?? null,
                                'employment_key' => $item['employmentKey'] ?? null,
                                'is_online' => $item['isOnline'] ?? null,
                                'views_num' => $item['viewsNum'] ?? null,
                                'areas' => $item['areas'] ?? [],
                                'languages' => $item['languages'] ?? [],
                                'search_keyword' => $plan['keyword'],
                                'search_category' => $plan['category'],
                            ],
                        ];

                        if (count($jobs) >= $maxResults) {
                            break 3;
                        }

                        if ($planAdded >= $planCapacity) {
                            break;
                        }
                    }

                    if ($pageAdded === 0 || count($items) < $limit || $planAdded >= $planCapacity) {
                        break;
                    }
                }
            }

            return new ScrapeResult($this->getSourceKey(), $jobs === [] ? 'empty' : 'ok', $jobs);
        } catch (\Throwable $exception) {
            $status = $exception instanceof \App\Utils\BlockedSourceException ? 'blocked' : 'error';
            $this->logger->warning('HiredChina no disponible', ['message' => $exception->getMessage()]);
            return new ScrapeResult($this->getSourceKey(), $status, [], $exception->getMessage());
        }
    }

    private function fetchApiPage(int $page, int $limit, string $keyword, int $clientId, string $cookie): array
    {
        $url = 'https://www.hiredchina.com/api/v1/jobs?' . http_build_query([
            'page' => $page,
            'limit' => $limit,
            'kw' => $keyword,
            'isShow' => 'false',
            'isPc' => 'true',
            'clientId' => $clientId,
        ]);

        $headers = [
            'User-Agent' => $_ENV['HIREDCHINA_USER_AGENT'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0',
            'Accept' => '*/*',
            'Accept-Language' => 'es-ES,es;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer' => 'https://www.hiredchina.com/jobs',
            'Connection' => 'keep-alive',
            'Cookie' => $cookie,
        ];

        try {
            $client = new Client([
                'timeout' => 20,
                'connect_timeout' => 10,
                'http_errors' => false,
                'verify' => filter_var($_ENV['HTTP_VERIFY_SSL'] ?? 'false', FILTER_VALIDATE_BOOL),
                'headers' => $headers,
            ]);
            $response = $client->get($url);
            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();
        } catch (GuzzleException $exception) {
            $fallback = $this->fetchApiPageViaCurl($url, $headers);
            if ($fallback === null) {
                throw new HttpException($exception->getMessage(), 0, $exception);
            }

            $statusCode = $fallback['status'];
            $body = $fallback['body'];
        }

        if (in_array($statusCode, [403, 429, 503], true) || $this->looksBlocked($body)) {
            throw new \App\Utils\BlockedSourceException(sprintf('Fuente bloqueada o protegida [%s]', $statusCode));
        }

        if ($statusCode >= 400 || trim($body) === '') {
            throw new HttpException(sprintf('Respuesta HTTP no usable [%s]', $statusCode));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('HiredChina devolvió JSON no válido.');
        }

        if ((int) ($decoded['code'] ?? -1) !== 0) {
            throw new \RuntimeException('HiredChina devolvió un código de API no exitoso.');
        }

        return $decoded;
    }

    private function fetchApiPageViaCurl(string $url, array $headers): ?array
    {
        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('proc_open')) {
            return null;
        }

        $headersFile = tempnam(sys_get_temp_dir(), 'hiredchina_headers_');
        $bodyFile = tempnam(sys_get_temp_dir(), 'hiredchina_body_');
        if ($headersFile === false || $bodyFile === false) {
            return null;
        }

        $command = sprintf(
            'curl.exe -sS --compressed -D %s -o %s %s',
            escapeshellarg($headersFile),
            escapeshellarg($bodyFile),
            escapeshellarg($url)
        );

        foreach ($headers as $key => $value) {
            $command .= ' -H ' . escapeshellarg($key . ': ' . $value);
        }

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

        return [
            'status' => (int) end($matches[1]),
            'body' => $body,
        ];
    }

    private function extractLocation(array $item): string
    {
        if ((int) ($item['isOnline'] ?? 0) === 1) {
            return 'Online';
        }

        $areas = $item['areas'] ?? [];
        if (is_array($areas) && $areas !== []) {
            $names = [];
            foreach ($areas as $area) {
                $key = trim((string) ($area['areaKey'] ?? ''));
                if ($key !== '') {
                    $names[] = $this->humanizeSupportKey($key);
                }
            }

            $names = array_values(array_unique(array_filter($names)));
            if ($names !== []) {
                return implode(', ', $names);
            }
        }

        return trim((string) $this->humanizeSupportKey((string) ($item['nationKey'] ?? '')));
    }

    private function buildDescription(array $item): string
    {
        $parts = array_filter([
            $this->humanizeSupportKey((string) ($item['salaryKey'] ?? '')),
            $this->humanizeSupportKey((string) ($item['employmentKey'] ?? '')),
            $this->humanizeSupportKey((string) ($item['company']['industryKey'] ?? '')),
            ((int) ($item['isOnline'] ?? 0) === 1) ? 'online' : 'offline',
        ], static fn (string $value): bool => trim($value) !== '');

        return Str::slice(implode(' | ', $parts), 0, 600);
    }

    private function humanizeSupportKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/^support\.[^.]+\./', '', $value) ?? $value;
        $value = str_replace(['.&.', '.', '_'], [' & ', ' ', ' '], $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim(ucwords($value));
    }
}
