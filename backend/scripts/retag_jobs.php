<?php

declare(strict_types=1);

use App\Services\JobNormalizer;

require_once __DIR__ . '/../bootstrap.php';

$jobsPath = __DIR__ . '/../storage/jobs.json';

if (!file_exists($jobsPath)) {
    fwrite(STDERR, "jobs.json not found at {$jobsPath}" . PHP_EOL);
    exit(1);
}

$raw = file_get_contents($jobsPath);
if ($raw === false) {
    fwrite(STDERR, "Unable to read {$jobsPath}" . PHP_EOL);
    exit(1);
}

$jobs = json_decode($raw, true);
if (!is_array($jobs)) {
    fwrite(STDERR, "Invalid JSON in {$jobsPath}" . PHP_EOL);
    exit(1);
}

$normalizer = new JobNormalizer();
$extractTags = new ReflectionMethod(JobNormalizer::class, 'extractTags');
$extractTags->setAccessible(true);

$processed = 0;
$withTags = 0;

foreach ($jobs as &$job) {
    if (!is_array($job)) {
        continue;
    }

    $tags = $extractTags->invoke($normalizer, $job);
    $job['tags'] = is_array($tags) ? $tags : [];

    $processed++;
    if ($job['tags'] !== []) {
        $withTags++;
    }
}
unset($job);

$encoded = json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    fwrite(STDERR, 'Failed to encode updated jobs JSON' . PHP_EOL);
    exit(1);
}

if (file_put_contents($jobsPath, $encoded . PHP_EOL) === false) {
    fwrite(STDERR, "Unable to write {$jobsPath}" . PHP_EOL);
    exit(1);
}

echo "Processed jobs: {$processed}" . PHP_EOL;
echo "Jobs with at least one tag: {$withTags}" . PHP_EOL;
