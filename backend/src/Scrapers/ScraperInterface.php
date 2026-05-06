<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\DTO\ScrapeResult;

interface ScraperInterface
{
    public function getSourceKey(): string;

    public function scrape(array $config): ScrapeResult;
}
