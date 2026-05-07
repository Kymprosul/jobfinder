<?php

declare(strict_types=1);

namespace App\Services;

use App\Storage\StorageInterface;

final class LoggerService
{
    private array $contextStack = [];
    private array $buffer = [];
    private bool $isBuffering = false;

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

    public function startBuffering(): void
    {
        $this->isBuffering = true;
        $this->buffer = [];
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            $this->isBuffering = false;

            return;
        }

        $logs = $this->storage->load('logs', []);
        $logs = array_merge($logs, $this->buffer);
        $this->storage->save('logs', array_slice($logs, -3000));
        $this->buffer = [];
        $this->isBuffering = false;
    }

    private function write(string $level, string $message, array $context): void
    {
        $mergedContext = array_merge($this->currentContext(), $context);
        $entry = [
            'timestamp' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
            'level' => $level,
            'message' => $message,
            'run_id' => $mergedContext['run_id'] ?? null,
            'trigger' => $mergedContext['trigger'] ?? null,
            'source' => $mergedContext['source'] ?? null,
            'stage' => $mergedContext['stage'] ?? null,
            'context' => $mergedContext,
        ];

        if ($this->isBuffering) {
            $this->buffer[] = $entry;

            return;
        }

        $logs = $this->storage->load('logs', []);
        $logs[] = $entry;
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
