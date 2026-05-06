<?php

declare(strict_types=1);

set_time_limit(180);

$services = require __DIR__ . '/../bootstrap.php';

$summary = $services['runJobs']->run('cron');
$config = $services['config']->get();
$sendMode = (string) ($config['email']['send_mode'] ?? 'manual_and_automatic');
$emailSummary = null;

if (
    (bool) ($config['email']['enabled'] ?? false)
    && in_array($sendMode, ['automatic', 'manual_and_automatic'], true)
) {
    try {
        $emailSummary = $services['runJobs']->sendPendingReport('cron-email');
    } catch (\Throwable $exception) {
        $emailSummary = [
            'email_sent' => false,
            'message' => $exception->getMessage(),
        ];
    }
}

echo json_encode([
    'run' => $summary,
    'email' => $emailSummary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
