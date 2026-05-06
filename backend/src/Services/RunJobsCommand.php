<?php

declare(strict_types=1);

namespace App\Services;

final class RunJobsCommand
{
    public function __construct(private readonly RunJobsService $runJobsService)
    {
    }

    public function execute(string $trigger = 'cron'): array
    {
        return $this->runJobsService->run($trigger);
    }
}
