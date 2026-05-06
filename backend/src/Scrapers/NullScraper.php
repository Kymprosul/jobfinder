<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\DTO\ScrapeResult;
use App\Services\LoggerService;

final class NullScraper implements ScraperInterface
{
    public function __construct(
        private readonly LoggerService $logger,
        private readonly string $sourceKey,
        private readonly string $message
    ) {
    }

    public function getSourceKey(): string
    {
        return $this->sourceKey;
    }

    public function scrape(array $config): ScrapeResult
    {
        $this->logger->warning('Fuente desactivada en modo degradado', [
            'source' => $this->sourceKey,
            'message' => $this->message,
        ]);

        return new ScrapeResult($this->sourceKey, 'unavailable', [], $this->message);
    }
}
