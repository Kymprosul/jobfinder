<?php

declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

final class JsonStorage implements StorageInterface
{
    private array $map = [
        'config' => 'config.json',
        'jobs' => 'jobs.json',
        'preview_jobs' => 'preview_jobs.json',
        'sent_jobs' => 'sent_jobs.json',
        'logs' => 'logs.json',
        'runs' => 'runs.json',
    ];

    public function __construct(private readonly string $basePath)
    {
        if (!is_dir($basePath) && !mkdir($basePath, 0755, true) && !is_dir($basePath)) {
            throw new RuntimeException('No se pudo crear el directorio de storage');
        }
    }

    public function load(string $name, array $default = []): array
    {
        $path = $this->resolvePath($name);
        if (!file_exists($path)) {
            $this->save($name, $default);
            return $default;
        }

        return $this->readJsonFile($path, $default);
    }

    public function save(string $name, array $data): void
    {
        $path = $this->resolvePath($name);
        $this->writeJsonFile($path, $name, $data);
    }

    public function append(string $name, array $record): void
    {
        $path = $this->resolvePath($name);
        $lockHandle = $this->acquireLock($name);

        try {
            $current = $this->readJsonFile($path, []);
            $current[] = $record;
            $this->writeJsonFile($path, $name, $current);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function resolvePath(string $name): string
    {
        $fileName = $this->map[$name] ?? sprintf('%s.json', $name);

        return $this->basePath . DIRECTORY_SEPARATOR . $fileName;
    }

    private function readJsonFile(string $path, array $default): array
    {
        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return $default;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : $default;
    }

    private function writeJsonFile(string $path, string $name, array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException(sprintf('No se pudo serializar %s', $name));
        }

        $encoded .= PHP_EOL;
        $directory = dirname($path);
        $tempPath = tempnam($directory, 'json_');
        if ($tempPath === false) {
            throw new RuntimeException(sprintf('No se pudo crear fichero temporal para %s', $name));
        }

        if (file_put_contents($tempPath, $encoded, LOCK_EX) === false) {
            @unlink($tempPath);
            throw new RuntimeException(sprintf('No se pudo escribir %s', $name));
        }

        if (!@rename($tempPath, $path)) {
            @unlink($tempPath);
            throw new RuntimeException(sprintf('No se pudo persistir %s', $name));
        }
    }

    /**
     * @return resource
     */
    private function acquireLock(string $name)
    {
        $lockPath = $this->basePath . DIRECTORY_SEPARATOR . $name . '.lock';
        $handle = fopen($lockPath, 'c+');

        if ($handle === false) {
            throw new RuntimeException(sprintf('No se pudo abrir el lock de %s', $name));
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException(sprintf('No se pudo bloquear %s', $name));
        }

        return $handle;
    }
}
