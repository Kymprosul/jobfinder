<?php

declare(strict_types=1);

use App\Utils\HttpException;

set_time_limit(180);

$services = require __DIR__ . '/../bootstrap.php';
$controller = $services['controller'];
$appEnv = strtolower(trim((string) ($_ENV['APP_ENV'] ?? 'prod')));
$appDebug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

$requestOrigin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
$allowedOrigins = array_values(array_unique(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) ($_ENV['APP_ALLOWED_ORIGINS'] ?? ''))
))));

$appBaseUrl = rtrim(trim((string) ($_ENV['APP_BASE_URL'] ?? '')), '/');
if ($appBaseUrl !== '') {
    $allowedOrigins[] = $appBaseUrl;
}

if ($requestOrigin !== '' && in_array(rtrim($requestOrigin, '/'), $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
} elseif ($appEnv !== 'prod' && $appBaseUrl !== '') {
    header('Access-Control-Allow-Origin: ' . $appBaseUrl);
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
if ($scriptDirectory !== '/' && $scriptDirectory !== '.') {
    $normalizedDirectory = rtrim($scriptDirectory, '/');
    if ($normalizedDirectory !== '' && str_starts_with($path, $normalizedDirectory)) {
        $path = substr($path, strlen($normalizedDirectory)) ?: '/';
    }
}

$routes = [];

$registerRoute = static function (string $method, string $path, callable|array $handler) use (&$routes): void {
    $routes[sprintf('%s %s', $method, $path)] = $handler;

    if (str_starts_with($path, '/api/')) {
        $routes[sprintf('%s %s', $method, substr($path, 4))] = $handler;
    }
};

$registerRoute('GET', '/api/config', [$controller, 'getConfig']);
$registerRoute('POST', '/api/config', [$controller, 'saveConfig']);
$registerRoute('GET', '/api/jobs', [$controller, 'getJobs']);
$registerRoute('GET', '/api/preview', [$controller, 'getPreviewJobs']);
$registerRoute('GET', '/api/runs', [$controller, 'getRuns']);
$registerRoute('GET', '/api/logs', [$controller, 'getLogs']);
$registerRoute('POST', '/api/run', [$controller, 'runNow']);
$registerRoute('POST', '/api/send-report', [$controller, 'sendPendingReport']);
$registerRoute('POST', '/api/import', [$controller, 'importJobs']);
$registerRoute('POST', '/api/jobs/{id}/reject', [$controller, 'rejectJob']);
$registerRoute('GET', '/api/status', [$controller, 'getStatus']);

$handleRejectRoute = static function (string $id) use ($controller, $appDebug): void {
    try {
        $controller->rejectJob($id);
    } catch (HttpException $exception) {
        http_response_code($exception->getStatusCode());
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error interno del servidor',
            'message' => $appDebug ? $exception->getMessage() : null,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    exit;
};

if ($method === 'POST' && preg_match('#^/api/jobs/([^/]+)/reject$#', $path, $matches) === 1) {
    $handleRejectRoute(urldecode((string) ($matches[1] ?? '')));
}

if ($method === 'POST' && preg_match('#^/jobs/([^/]+)/reject$#', $path, $matches) === 1) {
    $handleRejectRoute(urldecode((string) ($matches[1] ?? '')));
}

// POST /api/run/{source} — run a single scraper
if ($method === 'POST' && preg_match('#^/api/run/([^/]+)$#', $path, $matches) === 1) {
    $sourceKey = urldecode((string) ($matches[1] ?? ''));
    try {
        $response = $controller->runSource($sourceKey);
        http_response_code($response['status'] ?? 200);
        unset($response['status']);
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error interno del servidor',
            'message' => $appDebug ? $exception->getMessage() : null,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit;
}

$key = sprintf('%s %s', $method, $path);

if (!isset($routes[$key])) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Ruta no encontrada',
        'path' => $path,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    $response = call_user_func($routes[$key]);
    if ($response === null) {
        exit;
    }
    $statusCode = $response['status'] ?? 200;
    http_response_code($statusCode);
    unset($response['status']);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (HttpException $exception) {
    http_response_code($exception->getStatusCode());
    echo json_encode([
        'success' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor',
        'message' => $appDebug ? $exception->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
