<?php

declare(strict_types=1);

namespace App\Services;

use App\Storage\StorageInterface;

final class LoggerService
{
    private array $contextStack = [];

    public function __construct(private readonly StorageInterface $storage)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function withContext(array $context, callable $callback): mixed
    {
        $this->contextStack[] = $context;

        try {
            return $callback();
        } finally {
            array_pop($this->contextStack);
        }
    }

    private function write(string $level, string $message, array $context): void
    {
        $mergedContext = array_merge($this->currentContext(), $context);
        $logs = $this->storage->load('logs', []);
        $logs[] = [
            'timestamp' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
            'level' => $level,
            'message' => $message,
            'run_id' => $mergedContext['run_id'] ?? null,
            'trigger' => $mergedContext['trigger'] ?? null,
            'source' => $mergedContext['source'] ?? null,
            'stage' => $mergedContext['stage'] ?? null,
            'context' => $mergedContext,
        ];
        $this->storage->save('logs', array_slice($logs, -3000));
    }

    private function currentContext(): array
    {
        $context = [];

        foreach ($this->contextStack as $item) {
            $context = array_merge($context, $item);
        }

        return $context;
    }
}
