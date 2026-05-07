<?php

declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

final class RejectedJobsStorage
{
    private ?array $cache = null;

    /** @var array<string, true> */
    private array $dedupeKeys = [];

    /** @var array<string, true> */
    private array $overlapKeys = [];

    /** @var array<string, true> */
    private array $cleanUrls = [];

    public function __construct(private readonly string $basePath)
    {
        if (!is_dir($basePath) && !mkdir($basePath, 0755, true) && !is_dir($basePath)) {
            throw new RuntimeException('No se pudo crear el directorio de storage');
        }
    }

    public function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = $this->resolvePath();
        if (!file_exists($path)) {
            $this->save([]);
            $this->cache = [];

            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            $this->cache = [];

            return [];
        }

        $decoded = json_decode($content, true);
        $this->cache = is_array($decoded) ? $decoded : [];
        $this->rebuildIndex();

        return $this->cache;
    }

    public function save(array $rejected): void
    {
        $path = $this->resolvePath();
        $encoded = json_encode($rejected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException('No se pudo serializar rejected_jobs');
        }

        $encoded .= PHP_EOL;
        $directory = dirname($path);
        $tempPath = tempnam($directory, 'json_');
        if ($tempPath === false) {
            throw new RuntimeException('No se pudo crear fichero temporal para rejected_jobs');
        }

        if (file_put_contents($tempPath, $encoded, LOCK_EX) === false) {
            @unlink($tempPath);
            throw new RuntimeException('No se pudo escribir rejected_jobs');
        }

        if (!@rename($tempPath, $path)) {
            @unlink($tempPath);
            throw new RuntimeException('No se pudo persistir rejected_jobs');
        }

        $this->cache = $rejected;
        $this->rebuildIndex();
    }

    public function isRejected(array $job): bool
    {
        if ($this->cache === null) {
            $this->load();
        }

        $key = $this->toNullableString($job['dedupe_key'] ?? null);
        if ($key !== null && isset($this->dedupeKeys[$key])) {
            return true;
        }

        $key = $this->toNullableString($job['overlap_key'] ?? null);
        if ($key !== null && isset($this->overlapKeys[$key])) {
            return true;
        }

        $key = $this->toNullableString($job['clean_url'] ?? null);
        if ($key !== null && isset($this->cleanUrls[$key])) {
            return true;
        }

        return false;
    }

    public function add(array $job, string $reason = 'manual'): void
    {
        if ($this->isRejected($job)) {
            return;
        }

        $rejected = $this->load();
        $rejected[] = [
            'id' => $this->toNullableString($job['id'] ?? null),
            'dedupe_key' => $this->toNullableString($job['dedupe_key'] ?? null),
            'overlap_key' => $this->toNullableString($job['overlap_key'] ?? null),
            'url' => $this->toNullableString($job['url'] ?? null),
            'clean_url' => $this->toNullableString($job['clean_url'] ?? null),
            'title' => $this->toNullableString($job['title'] ?? null),
            'institution' => $this->toNullableString($job['institution'] ?? null),
            'source' => $this->toNullableString($job['source'] ?? null),
            'category' => $this->toNullableString($job['category'] ?? null),
            'rejected_at' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
            'reason' => trim($reason) !== '' ? $reason : 'manual',
        ];

        $this->save($rejected);
    }

    private function rebuildIndex(): void
    {
        $this->dedupeKeys = [];
        $this->overlapKeys = [];
        $this->cleanUrls = [];

        if ($this->cache === null) {
            return;
        }

        foreach ($this->cache as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $key = $this->toNullableString($entry['dedupe_key'] ?? null);
            if ($key !== null) {
                $this->dedupeKeys[$key] = true;
            }

            $key = $this->toNullableString($entry['overlap_key'] ?? null);
            if ($key !== null) {
                $this->overlapKeys[$key] = true;
            }

            $key = $this->toNullableString($entry['clean_url'] ?? null);
            if ($key !== null) {
                $this->cleanUrls[$key] = true;
            }
        }
    }

    private function toNullableString(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function resolvePath(): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'rejected_jobs.json';
    }
}
