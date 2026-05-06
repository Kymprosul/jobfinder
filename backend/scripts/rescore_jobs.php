<?php

declare(strict_types=1);

use App\Services\JobFilterService;

require_once __DIR__ . '/../bootstrap.php';

$configPath = __DIR__ . '/../storage/config.json';
$jobsPath = __DIR__ . '/../storage/jobs.json';

$configRaw = file_get_contents($configPath);
if ($configRaw === false) {
    fwrite(STDERR, "Unable to read {$configPath}" . PHP_EOL);
    exit(1);
}

$jobsRaw = file_get_contents($jobsPath);
if ($jobsRaw === false) {
    fwrite(STDERR, "Unable to read {$jobsPath}" . PHP_EOL);
    exit(1);
}

$config = json_decode($configRaw, true);
if (!is_array($config)) {
    fwrite(STDERR, "Invalid JSON in {$configPath}" . PHP_EOL);
    exit(1);
}

$jobs = json_decode($jobsRaw, true);
if (!is_array($jobs)) {
    fwrite(STDERR, "Invalid JSON in {$jobsPath}" . PHP_EOL);
    exit(1);
}

$filterService = new JobFilterService();

$processed = 0;
$keptScoreAboveZero = 0;
$droppedToZero = 0;

foreach ($jobs as $index => $job) {
    if (!is_array($job)) {
        continue;
    }

    $previousScore = (int) ($job['score'] ?? 0);

    unset($job['score'], $job['category'], $job['matched_keywords']);

    $evaluated = $filterService->evaluateJobs([$job], $config);
    $freshJob = $evaluated[0]['job'] ?? $job;

    $jobs[$index]['score'] = (int) ($freshJob['score'] ?? 0);
    $jobs[$index]['category'] = $freshJob['category'] ?? null;
    $jobs[$index]['matched_keywords'] = is_array($freshJob['matched_keywords'] ?? null)
        ? $freshJob['matched_keywords']
        : [];

    $processed++;

    if ($jobs[$index]['score'] > 0) {
        $keptScoreAboveZero++;
    }

    if ($previousScore > 0 && $jobs[$index]['score'] === 0) {
        $droppedToZero++;
    }
}

$encoded = json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    fwrite(STDERR, 'Failed to encode updated jobs JSON' . PHP_EOL);
    exit(1);
}

if (file_put_contents($jobsPath, $encoded . PHP_EOL) === false) {
    fwrite(STDERR, "Unable to write {$jobsPath}" . PHP_EOL);
    exit(1);
}

echo "Total processed: {$processed}" . PHP_EOL;
echo "Kept score > 0: {$keptScoreAboveZero}" . PHP_EOL;
echo "Dropped to 0: {$droppedToZero}" . PHP_EOL;
