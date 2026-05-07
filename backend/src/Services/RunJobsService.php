<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\MailService;
use App\Scrapers\ScraperInterface;
use App\Storage\RejectedJobsStorage;
use App\Storage\StorageInterface;

final class RunJobsService
{
    /**
     * @param ScraperInterface[] $scrapers
     */
    public function __construct(
        private readonly ConfigService $configService,
        private readonly StorageInterface $storage,
        private readonly LoggerService $logger,
        private readonly JobNormalizer $normalizer,
        private readonly JobFilterService $filterService,
        private readonly DeduplicationService $deduplicationService,
        private readonly ReportService $reportService,
        private readonly MailService $mailService,
        private readonly array $scrapers,
        private readonly RejectedJobsStorage $rejectedJobsStorage
    ) {
    }

    public function run(string $trigger = 'manual'): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $config = $this->configService->get();
        $startedAt = new \DateTimeImmutable('now');
        $runId = hash('sha1', $startedAt->format(DATE_ATOM) . $trigger);

        return $this->logger->withContext([
            'run_id' => $runId,
            'trigger' => $trigger,
        ], function () use ($config, $startedAt, $trigger, $runId): array {
            $this->logger->startBuffering();

            try {
                return $this->executeRun($config, $startedAt, $trigger, $runId);
            } finally {
                $this->logger->flush();
            }
        });
    }

    private function executeRun(array $config, \DateTimeImmutable $startedAt, string $trigger, string $runId): array
    {
            $searchLabels = array_values(array_filter(array_map(
                static fn (array $search): string => trim((string) ($search['label'] ?? '')),
                $config['searches'] ?? []
            )));

            $this->logger->info('Inicio de ejecución', [
                'stage' => 'run_start',
                'active_sources' => count(array_filter(
                    $config['sources'],
                    static fn (array $source): bool => (bool) ($source['enabled'] ?? false)
                )),
                'searches' => $searchLabels,
            ]);

            $rawJobs = [];
            $sourceResults = [];
            $errorCount = 0;

            foreach ($this->scrapers as $scraper) {
                $sourceKey = $scraper->getSourceKey();
                $sourceConfig = $config['sources'][$sourceKey] ?? ['enabled' => false];

                if (!(bool) ($sourceConfig['enabled'] ?? false)) {
                    $sourceResults[] = [
                        'source' => $sourceKey,
                        'status' => 'disabled',
                        'jobs_count' => 0,
                    ];
                    $this->logger->info('Fuente omitida', [
                        'stage' => 'source_skip',
                        'source' => $sourceKey,
                        'reason' => 'disabled',
                    ]);
                    continue;
                }

                $sourceStartedAt = microtime(true);
                $sourceContext = [
                    'source' => $sourceKey,
                ];

                $result = $this->logger->withContext($sourceContext, function () use ($scraper, $config, $sourceConfig): \App\DTO\ScrapeResult {
                    $this->logger->info('Inicio de scraping de fuente', [
                        'stage' => 'source_start',
                        'max_pages' => $sourceConfig['max_pages'] ?? null,
                        'max_results' => $sourceConfig['max_results'] ?? null,
                    ]);

                    return $scraper->scrape($config);
                });

                $durationMs = (int) round((microtime(true) - $sourceStartedAt) * 1000);
                $sourceResults[] = [
                    'source' => $sourceKey,
                    'status' => $result->status,
                    'jobs_count' => count($result->jobs),
                    'message' => $result->message,
                    'duration_ms' => $durationMs,
                ];

                $this->logger->info('Fin de scraping de fuente', [
                    'stage' => 'source_finish',
                    'source' => $sourceKey,
                    'status' => $result->status,
                    'jobs_count' => count($result->jobs),
                    'duration_ms' => $durationMs,
                    'message' => $result->message,
                ]);

                if (!in_array($result->status, ['ok', 'empty', 'partial', 'disabled'], true)) {
                    $errorCount++;
                }

                $rawJobs = array_merge($rawJobs, $result->jobs);
            }

            $normalizedJobs = array_map(fn (array $job): array => $this->normalizer->normalize($job), $rawJobs);
            $this->logger->info('Normalización completada', [
                'stage' => 'normalize',
                'raw_jobs' => count($rawJobs),
                'normalized_jobs' => count($normalizedJobs),
            ]);

            $filtered = $this->filterService->filter($normalizedJobs, $config);
            $acceptedNotRejected = array_values(array_filter(
                $filtered['accepted'],
                fn (array $job): bool => !$this->rejectedJobsStorage->isRejected($job)
            ));
            $this->logger->info('Filtrado completado', [
                'stage' => 'filter',
                'evaluated_jobs' => count($filtered['evaluated']),
                'accepted_jobs' => count($acceptedNotRejected),
                'discarded' => $filtered['discarded'],
                'accepted_by_search' => $this->countJobsByCategory($acceptedNotRejected),
            ]);

            $previewJobs = array_map(static function (array $evaluated): array {
                $job = $evaluated['job'];
                $job['accepted'] = $evaluated['accepted'];
                $job['rejection_reason'] = $evaluated['accepted'] ? null : ($evaluated['reason'] ?? 'irrelevant');

                return $job;
            }, $filtered['evaluated']);
            usort($previewJobs, static fn (array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
            $this->storage->save('preview_jobs', $previewJobs);
            $this->logger->info('Preview guardada', [
                'stage' => 'preview_save',
                'preview_jobs' => count($previewJobs),
            ]);

            $existingJobs = $this->storage->load('jobs', []);
            $merged = $this->deduplicationService->merge($existingJobs, $acceptedNotRejected);
            $jobs = $merged['jobs'];
            usort($jobs, static fn (array $a, array $b): int => strcmp($b['last_seen_at'] ?? '', $a['last_seen_at'] ?? ''));
            $this->storage->save('jobs', $jobs);

            $newJobs = array_values(array_filter(
                $merged['new_jobs'],
                static fn (array $job): bool => !($job['sent'] ?? false)
            ));

            $this->logger->info('Histórico actualizado', [
                'stage' => 'jobs_save',
                'existing_jobs_before' => count($existingJobs),
                'jobs_after' => count($jobs),
                'new_jobs' => count($newJobs),
                'duplicates_discarded' => $merged['duplicates_discarded'],
                'new_jobs_by_search' => $this->countJobsByCategory($newJobs),
            ]);

            $finishedAt = new \DateTimeImmutable('now');
            $summary = [
                'id' => $runId,
                'trigger' => $trigger,
                'started_at' => $startedAt->format(DATE_ATOM),
                'finished_at' => $finishedAt->format(DATE_ATOM),
                'duration_ms' => $this->durationMs($startedAt, $finishedAt),
                'sources_processed' => count(array_filter($sourceResults, static fn (array $result): bool => $result['status'] !== 'disabled')),
                'source_results' => $sourceResults,
                'raw_jobs' => count($rawJobs),
                'accepted_jobs' => count($acceptedNotRejected),
                'accepted_jobs_by_search' => $this->countJobsByCategory($acceptedNotRejected),
                'new_jobs_count' => count($newJobs),
                'new_jobs_by_search' => $this->countJobsByCategory($newJobs),
                'duplicates_discarded' => $merged['duplicates_discarded'],
                'discarded' => $filtered['discarded'],
                'errors' => $errorCount,
                'email_sent' => false,
                'pending_jobs_count' => count(array_filter(
                    $jobs,
                    static fn (array $job): bool => !($job['sent'] ?? false)
                )),
            ];

            $runs = $this->storage->load('runs', []);
            $runs[] = $summary;
            $this->storage->save('runs', array_slice($runs, -200));
            $this->logger->info('Resumen de ejecución guardado', [
                'stage' => 'run_summary',
                'duration_ms' => $summary['duration_ms'],
                'sources_processed' => $summary['sources_processed'],
                'errors' => $summary['errors'],
            ]);

            $this->logger->info('Fin de ejecución', [
                'stage' => 'run_finish',
                'new_jobs' => count($newJobs),
                'pending_jobs' => $summary['pending_jobs_count'],
                'email_sent' => false,
            ]);

            return $summary;
    }

    public function sendPendingReport(string $trigger = 'manual-send'): array
    {
        return $this->logger->withContext([
            'trigger' => $trigger,
        ], function () use ($trigger): array {
            $config = $this->configService->get();
            $jobs = $this->storage->load('jobs', []);
            $pendingJobs = array_values(array_filter(
                $jobs,
                static fn (array $job): bool => !($job['sent'] ?? false)
            ));

            $this->logger->info('Inicio de envío de email pendiente', [
                'stage' => 'email_start',
                'pending_jobs' => count($pendingJobs),
            ]);

            if (!(bool) ($config['email']['enabled'] ?? false)) {
                throw new \RuntimeException('El envío automático/manual está desactivado en configuración.');
            }

            if ($pendingJobs === []) {
                throw new \RuntimeException('No hay ofertas aceptadas pendientes de enviar.');
            }

            $subject = sprintf('Reporte diario de ofertas - %s', (new \DateTimeImmutable('now'))->format('d/m/Y'));
            $html = $this->reportService->buildHtml($pendingJobs, [
                'new_jobs_count' => count($pendingJobs),
                'errors' => 0,
                'duplicates_discarded' => 0,
                'discarded' => [
                    'too_old' => 0,
                ],
            ], $config);

            $emailSent = $this->mailService->sendReport((string) ($config['email']['to'] ?? ''), $subject, $html);
            if (!$emailSent) {
                throw new \RuntimeException('No se pudo enviar el email pendiente. Revisa destinatario, SMTP y logs.');
            }

            $sentAt = (new \DateTimeImmutable('now'))->format(DATE_ATOM);
            $jobsById = [];
            foreach ($jobs as $job) {
                $jobsById[$job['id']] = $job;
            }

            foreach ($pendingJobs as $job) {
                if (!isset($jobsById[$job['id']])) {
                    continue;
                }

                $jobsById[$job['id']]['sent'] = true;
                $this->storage->append('sent_jobs', [
                    'job_id' => $job['id'],
                    'source' => $job['source'],
                    'sent_at' => $sentAt,
                    'trigger' => $trigger,
                ]);
            }

            $jobs = array_values($jobsById);
            usort($jobs, static fn (array $a, array $b): int => strcmp($b['last_seen_at'] ?? '', $a['last_seen_at'] ?? ''));
            $this->storage->save('jobs', $jobs);

            $this->logger->info('Email pendiente enviado', [
                'stage' => 'email_finish',
                'jobs_sent' => count($pendingJobs),
                'sent_at' => $sentAt,
            ]);

            return [
                'trigger' => $trigger,
                'email_sent' => true,
                'jobs_sent' => count($pendingJobs),
                'sent_at' => $sentAt,
            ];
        });
    }

    private function countJobsByCategory(array $jobs): array
    {
        $counts = [];

        foreach ($jobs as $job) {
            $category = trim((string) ($job['category'] ?? 'sin_categoria'));
            if ($category === '') {
                $category = 'sin_categoria';
            }

            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    private function durationMs(\DateTimeImmutable $startedAt, \DateTimeImmutable $finishedAt): int
    {
        $started = (float) $startedAt->format('U.u');
        $finished = (float) $finishedAt->format('U.u');

        return (int) round(($finished - $started) * 1000);
    }
}
