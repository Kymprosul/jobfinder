<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ConfigService;
use App\Services\DeduplicationService;
use App\Services\JobFilterService;
use App\Services\JobNormalizer;
use App\Services\LoggerService;
use App\Services\RunJobsService;
use App\Storage\RejectedJobsStorage;
use App\Storage\StorageInterface;
use App\Utils\HttpException;

final class ApiController
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly StorageInterface $storage,
        private readonly LoggerService $logger,
        private readonly RunJobsService $runJobsService,
        private readonly JobNormalizer $normalizer,
        private readonly JobFilterService $filterService,
        private readonly DeduplicationService $deduplicationService,
        private readonly RejectedJobsStorage $rejectedJobsStorage,
        private readonly bool $dependenciesAvailable = true
    ) {
    }

    public function importJobs(): void
    {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) {
            http_response_code(400);
            echo json_encode([
                'error' => 'invalid payload',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            return;
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            http_response_code(400);
            echo json_encode([
                'error' => 'invalid payload',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            return;
        }

        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode([
                'error' => 'invalid payload',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            return;
        }

        $isSequentialArray = array_keys($payload) === range(0, count($payload) - 1);
        if (!$isSequentialArray) {
            http_response_code(400);
            echo json_encode([
                'error' => 'invalid payload',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            return;
        }

        if ($payload === []) {
            http_response_code(200);
            echo json_encode([
                'ok' => true,
                'imported' => 0,
                'new' => 0,
                'duplicates' => 0,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            return;
        }

        $config = $this->configService->get();
        $normalizedJobs = [];

        foreach ($payload as $rawJob) {
            if (!is_array($rawJob)) {
                continue;
            }

            $source = trim((string) ($rawJob['source'] ?? ''));
            if ($source === '') {
                $rawJob['source'] = 'playwright:import';
            }

            $normalizedJobs[] = $this->normalizer->normalize($rawJob);
        }

        $filtered = $this->filterService->filter($normalizedJobs, $config);
        $acceptedNotRejected = array_values(array_filter(
            $filtered['accepted'],
            fn (array $job): bool => !$this->rejectedJobsStorage->isRejected($job)
        ));

        $existingJobs = $this->storage->load('jobs', []);
        $merged = $this->deduplicationService->merge($existingJobs, $acceptedNotRejected);
        $this->storage->save('jobs', $merged['jobs']);

        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'imported' => count($payload),
            'accepted' => count($acceptedNotRejected),
            'new' => count($merged['new_jobs']),
            'duplicates' => $merged['duplicates_discarded'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function rejectJob(string $id): void
    {
        $jobs = $this->storage->load('jobs', []);
        $job = null;

        foreach ($jobs as $candidate) {
            if (($candidate['id'] ?? null) === $id) {
                $job = $candidate;
                break;
            }
        }

        if ($job === null) {
            http_response_code(404);
            echo json_encode([
                'error' => 'not found',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            return;
        }

        $this->rejectedJobsStorage->add($job, 'manual');

        $jobs = array_values(array_filter(
            $jobs,
            static fn (array $candidate): bool => ($candidate['id'] ?? null) !== $id
        ));
        $this->storage->save('jobs', $jobs);

        http_response_code(200);
        echo json_encode([
            'ok' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function getConfig(): array
    {
        return [
            'success' => true,
            'data' => $this->configService->getPublicConfig(),
        ];
    }

    public function saveConfig(): array
    {
        $payload = $this->readJsonBody();
        $config = $this->configService->save($payload);

        return [
            'success' => true,
            'message' => 'Configuración guardada',
            'data' => $config,
        ];
    }

    public function getJobs(): array
    {
        $jobs = $this->loadJobsByCollection('jobs');

        return [
            'success' => true,
            'data' => $jobs,
            'meta' => [
                'total' => count($jobs),
            ],
        ];
    }

    public function getPreviewJobs(): array
    {
        $jobs = $this->loadJobsByCollection('preview_jobs');

        return [
            'success' => true,
            'data' => $jobs,
            'meta' => [
                'total' => count($jobs),
            ],
        ];
    }

    public function getRuns(): array
    {
        return [
            'success' => true,
            'data' => $this->loadSortedRecords('runs', 'started_at', 50),
        ];
    }

    public function getLogs(): array
    {
        return [
            'success' => true,
            'data' => $this->loadSortedRecords('logs', 'timestamp', 500),
        ];
    }

    public function runNow(): array
    {
        $summary = $this->runJobsService->run('manual');

        return [
            'success' => true,
            'message' => 'Ejecución completada',
            'data' => $summary,
        ];
    }

    public function sendPendingReport(): array
    {
        $summary = $this->runJobsService->sendPendingReport('manual-dashboard-send');

        return [
            'success' => true,
            'message' => 'Email enviado',
            'data' => $summary,
        ];
    }

    public function getStatus(): array
    {
        $runs = $this->loadSortedRecords('runs', 'started_at');
        $lastRun = $runs[0] ?? null;
        $jobs = $this->storage->load('jobs', []);
        $pendingJobsCount = count(array_filter(
            $jobs,
            static fn (array $job): bool => !($job['sent'] ?? false)
        ));
        $sentJobs = $this->loadSortedRecords('sent_jobs', 'sent_at');
        $lastSent = $sentJobs[0]['sent_at'] ?? null;
        $sources = $this->configService->get()['sources'] ?? [];

        return [
            'success' => true,
            'data' => [
                'app' => 'Jobfinder',
                'last_run' => $lastRun,
                'pending_jobs_count' => $pendingJobsCount,
                'last_email_sent_at' => $lastSent,
                'smtp_configured' => $this->configService->isSmtpConfigured(),
                'dependencies_available' => $this->dependenciesAvailable,
                'active_sources' => count(array_filter(
                    $sources,
                    static fn (array $source): bool => (bool) ($source['enabled'] ?? false)
                )),
            ],
        ];
    }

    private function readJsonBody(): array
    {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new HttpException('JSON inválido en la petición', 400);
        }

        if (!is_array($decoded)) {
            throw new HttpException('El cuerpo JSON debe ser un objeto o array', 400);
        }

        return $decoded;
    }

    private function readQueryParam(string $name): ?string
    {
        $value = $_GET[$name] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return function_exists('mb_substr')
            ? mb_substr($value, 0, 120)
            : substr($value, 0, 120);
    }

    private function compareJobsByPublishedDate(array $a, array $b): int
    {
        $postedDateComparison = strcmp($b['posted_date'] ?? '', $a['posted_date'] ?? '');
        if ($postedDateComparison !== 0) {
            return $postedDateComparison;
        }

        return strcmp($b['first_seen_at'] ?? '', $a['first_seen_at'] ?? '');
    }

    private function loadJobsByCollection(string $collection): array
    {
        $jobs = $this->storage->load($collection, []);

        if ($collection === 'jobs') {
            $jobs = array_values(array_filter(
                $jobs,
                fn (array $job): bool => !$this->rejectedJobsStorage->isRejected($job)
            ));
        }

        $category = $this->readQueryParam('category');

        if ($category !== null) {
            $jobs = array_values(array_filter(
                $jobs,
                static fn (array $job): bool => ($job['category'] ?? null) === $category
            ));
        }

        usort($jobs, [$this, 'compareJobsByPublishedDate']);

        return $jobs;
    }

    private function loadSortedRecords(string $collection, string $field, ?int $limit = null): array
    {
        $records = $this->storage->load($collection, []);
        usort($records, static fn (array $a, array $b): int => strcmp($b[$field] ?? '', $a[$field] ?? ''));

        return $limit !== null ? array_slice($records, 0, $limit) : $records;
    }
}
