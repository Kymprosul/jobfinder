<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\DTO\ScrapeResult;
use App\Utils\DateParser;
use App\Utils\BlockedSourceException;
use App\Utils\HttpException;
use App\Utils\Str;

final class HigherEdJobsScraper extends AbstractScraper
{
    private const SEARCH_ENDPOINT = 'https://www.higheredjobs.com/assets/api/searchResults.cfc';
    private const COUNTRY_CODE = 44;

    public function getSourceKey(): string
    {
        return 'higheredjobs';
    }

    public function scrape(array $config): ScrapeResult
    {
        try {
            $jobs = $this->fetchJobs($config['sources'][$this->getSourceKey()] ?? []);
        } catch (\Throwable $exception) {
            try {
                $fallbackJobs = $this->fetchJobsViaMirror($config['sources'][$this->getSourceKey()] ?? []);
                if ($fallbackJobs !== []) {
                    return new ScrapeResult(
                        $this->getSourceKey(),
                        'partial',
                        $fallbackJobs,
                        'Acceso directo no usable; usando fallback público de contenido cacheado.'
                    );
                }
            } catch (\Throwable) {
                // Keep original exception outcome when mirror fallback also fails.
            }

            $status = $exception instanceof BlockedSourceException ? 'blocked' : 'error';
            $this->logger->warning('HigherEdJobs bloqueado o no usable', ['message' => $exception->getMessage()]);
            return new ScrapeResult($this->getSourceKey(), $status, [], $exception->getMessage());
        }

        return new ScrapeResult($this->getSourceKey(), $jobs === [] ? 'empty' : 'ok', $jobs);
    }

    private function fetchJobs(array $sourceConfig): array
    {
        [$statusCode, $body] = $this->requestResultsPayload();

        if (in_array($statusCode, [403, 429, 503], true) || $this->looksBlocked($body)) {
            throw new BlockedSourceException(sprintf('Fuente bloqueada o protegida [%s]', $statusCode));
        }

        if ($statusCode >= 400 || trim($body) === '') {
            throw new HttpException(sprintf('Respuesta HTTP no usable [%s]', $statusCode));
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new HttpException('HigherEdJobs devolvió una respuesta JSON no válida', 0, $exception);
        }

        if (!is_array($payload) || (int) ($payload['success'] ?? 0) !== 1) {
            throw new HttpException('HigherEdJobs devolvió una respuesta sin resultados utilizables');
        }

        $records = $payload['data']['ARYSEARCHJOBS'] ?? [];
        if (!is_array($records)) {
            return [];
        }

        $jobs = [];
        $seenJobCodes = [];
        $maxResults = max(0, (int) ($sourceConfig['max_results'] ?? 0));

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $job = $this->mapJob($record);
            if ($job === null) {
                continue;
            }

            $jobCode = (string) ($record['JobCode'] ?? '');
            $dedupeKey = $jobCode !== '' ? $jobCode : hash('sha256', implode('|', [
                $job['title'],
                $job['institution'],
                $job['location'],
                $job['posted_date'] ?? '',
            ]));

            if (isset($seenJobCodes[$dedupeKey])) {
                continue;
            }

            $seenJobCodes[$dedupeKey] = true;
            $jobs[] = $job;

            if ($maxResults > 0 && count($jobs) >= $maxResults) {
                break;
            }
        }

        return $jobs;
    }

    private function requestResultsPayload(): array
    {
        try {
            $response = $this->client->post(self::SEARCH_ENDPOINT, [
                'form_params' => [
                    'method' => 'getResults',
                    'CountryCodeList' => (string) self::COUNTRY_CODE,
                    'RemoteTypes' => '1',
                    'sortBy' => '0',
                    'AllCatsReturned' => 'true',
                ],
            ]);

            return [
                $response->getStatusCode(),
                (string) $response->getBody(),
            ];
        } catch (\Throwable $exception) {
            $fallback = $this->requestResultsPayloadViaPowerShell();
            if ($fallback !== null) {
                return $fallback;
            }

            throw new HttpException($exception->getMessage(), 0, $exception);
        }
    }

    private function requestResultsPayloadViaPowerShell(): ?array
    {
        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('proc_open')) {
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'jobfinder_hej_');
        if ($tempFile === false) {
            return null;
        }

        $scriptFile = $tempFile . '.ps1';
        if (!@rename($tempFile, $scriptFile)) {
            @unlink($tempFile);
            return null;
        }

        $script = <<<'PS1'
$ProgressPreference = 'SilentlyContinue'
$body = @{
    method = 'getResults'
    CountryCodeList = '44'
    RemoteTypes = '1'
    sortBy = '0'
    AllCatsReturned = 'true'
}

try {
    $response = Invoke-WebRequest -UseBasicParsing 'https://www.higheredjobs.com/assets/api/searchResults.cfc' -Method Post -Body $body
    [Console]::Out.WriteLine('STATUS:' + [int] $response.StatusCode)
    [Console]::Out.Write($response.Content)
} catch {
    $statusCode = 0
    if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
        $statusCode = [int] $_.Exception.Response.StatusCode
    }

    [Console]::Out.WriteLine('STATUS:' + $statusCode)
    if ($_.Exception.Response) {
        $stream = $_.Exception.Response.GetResponseStream()
        if ($stream) {
            $reader = New-Object System.IO.StreamReader($stream)
            [Console]::Out.Write($reader.ReadToEnd())
            $reader.Dispose()
        }
    }

    exit 1
}
PS1;

        file_put_contents($scriptFile, $script);

        $command = sprintf(
            'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File %s',
            escapeshellarg($scriptFile)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($scriptFile);
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        @unlink($scriptFile);

        if (!preg_match('/^STATUS:(\d+)\R?/u', $stdout, $matches)) {
            return null;
        }

        $statusCode = (int) $matches[1];
        $body = (string) preg_replace('/^STATUS:\d+\R?/u', '', $stdout, 1);

        if ($exitCode !== 0 && trim($body) === '' && trim($stderr) === '') {
            return null;
        }

        return [$statusCode, $body];
    }

    private function mapJob(array $record): ?array
    {
        $title = trim((string) ($record['JobTitle'] ?? ''));
        if ($title === '') {
            return null;
        }

        $jobCode = trim((string) ($record['JobCode'] ?? ''));
        $institution = trim((string) ($record['InstName'] ?? ''));
        $location = trim((string) ($record['InstCity'] ?? ''));
        $jobCategory = trim((string) ($record['JobCatDesc'] ?? ''));
        $department = trim((string) ($record['Department'] ?? ''));
        $salary = trim((string) ($record['Salary'] ?? ''));

        $summary = array_filter([
            $title,
            $department !== '' ? 'Department: ' . $department : '',
            $salary !== '' ? 'Salary: ' . preg_replace('/\s+/u', ' ', $salary) : '',
            $institution !== '' ? 'Institution: ' . $institution : '',
            $location !== '' ? 'Location: ' . $location : '',
        ], static fn (string $value): bool => $value !== '');

        return [
            'source' => $this->getSourceKey(),
            'title' => $title,
            'institution' => $institution,
            'location' => $location,
            'url' => $jobCode !== ''
                ? sprintf('https://www.higheredjobs.com/details.cfm?JobCode=%s', rawurlencode($jobCode))
                : 'https://www.higheredjobs.com/international/search.cfm?CountryCode=44',
            'description' => Str::slice(implode(' | ', $summary), 0, 1000),
            'posted_date' => DateParser::parse((string) ($record['DatePosted'] ?? '')),
            'closing_date' => DateParser::parse((string) ($record['ApplyByDate'] ?? '')),
            'raw_meta' => [
                'job_code' => $jobCode,
                'job_category' => $jobCategory,
                'department' => $department,
                'salary' => $salary,
                'position_type' => (int) ($record['PositionType'] ?? 0),
                'institution_type' => (int) ($record['InstType'] ?? 0),
                'account_id' => (int) ($record['AccountID'] ?? 0),
                'country_code' => (int) ($record['InstCountryCode'] ?? self::COUNTRY_CODE),
                'source_url' => 'https://www.higheredjobs.com/international/search.cfm?CountryCode=44',
            ],
        ];
    }

    private function fetchJobsViaMirror(array $sourceConfig): array
    {
        $maxResults = max(1, (int) ($sourceConfig['max_results'] ?? 40));
        $mirrorUrl = 'https://r.jina.ai/http://www.higheredjobs.com/international/search.cfm?CountryCode=44';
        $markdown = $this->fetchHtml($mirrorUrl);
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $jobs = [];
        $seenJobCodes = [];

        for ($index = 0; $index < count($lines); $index++) {
            $line = trim((string) $lines[$index]);

            if (
                preg_match(
                    '/^\[(.+)\]\((http:\/\/www\.higheredjobs\.com\/international\/details\.cfm\?JobCode=(\d+)[^)]*)\)$/u',
                    $line,
                    $matches
                ) !== 1
            ) {
                continue;
            }

            $title = trim($matches[1]);
            $url = preg_replace('/^http:\/\//i', 'https://', trim($matches[2])) ?? trim($matches[2]);
            $jobCode = trim($matches[3]);

            if ($title === '' || $url === '' || $jobCode === '' || isset($seenJobCodes[$jobCode])) {
                continue;
            }

            $locationLine = trim((string) ($lines[$index + 1] ?? ''));
            $postedLine = trim((string) ($lines[$index + 2] ?? ''));
            $postedDate = null;
            if (preg_match('/Posted\s+(.+)$/iu', $postedLine, $postedMatches) === 1) {
                $postedDate = DateParser::parse($postedMatches[1]);
            }

            $description = implode(' | ', array_filter([
                $locationLine,
                $postedLine,
            ], static fn (string $value): bool => trim($value) !== ''));

            $jobs[] = [
                'source' => $this->getSourceKey(),
                'title' => $title,
                'institution' => '',
                'location' => trim(str_replace(' ,', ',', $locationLine)),
                'url' => $url,
                'description' => Str::slice($description !== '' ? $description : $title, 0, 1000),
                'posted_date' => $postedDate,
                'closing_date' => null,
                'raw_meta' => [
                    'job_code' => $jobCode,
                    'source_url' => 'https://www.higheredjobs.com/international/search.cfm?CountryCode=44',
                    'mirror_mode' => true,
                ],
            ];

            $seenJobCodes[$jobCode] = true;

            if (count($jobs) >= $maxResults) {
                break;
            }
        }

        return $jobs;
    }
}
