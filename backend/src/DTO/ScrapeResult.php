<?php

declare(strict_types=1);

namespace App\DTO;

final class ScrapeResult
{
    public function __construct(
        public readonly string $source,
        public readonly string $status,
        public readonly array $jobs = [],
        public readonly ?string $message = null,
        public readonly array $meta = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'status' => $this->status,
            'jobs' => $this->jobs,
            'message' => $this->message,
            'meta' => $this->meta,
        ];
    }
}
