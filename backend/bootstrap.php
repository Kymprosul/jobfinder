<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4));
    $path = __DIR__ . '/src/' . $relative . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

use App\Controllers\ApiController;
use App\Mail\MailService;
use App\Scrapers\ChinaJobScraper;
use App\Scrapers\ChinaTeachJobsScraper;
use App\Scrapers\ChinaUniversityJobsScraper;
use App\Scrapers\EChinaCitiesScraper;
use App\Scrapers\HigherEdJobsScraper;
use App\Scrapers\HiredChinaScraper;
use App\Scrapers\JoobleScraper;
use App\Scrapers\JobsCinaScraper;
use App\Scrapers\NullScraper;
use App\Scrapers\UnncScraper;
use App\Services\ConfigService;
use App\Services\DeduplicationService;
use App\Services\JobFilterService;
use App\Services\JobNormalizer;
use App\Services\LoggerService;
use App\Services\ReportService;
use App\Services\RunJobsService;
use App\Storage\JsonStorage;
use App\Storage\RejectedJobsStorage;
use App\Utils\EnvLoader;

$vendorAutoload = __DIR__ . '/vendor/autoload.php';
$dependenciesAvailable = file_exists($vendorAutoload);

if ($dependenciesAvailable) {
    require_once $vendorAutoload;
}

EnvLoader::load(__DIR__ . '/.env');

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

$storage = new JsonStorage(__DIR__ . '/storage');
$rejectedJobsStorage = new RejectedJobsStorage(__DIR__ . '/storage');
$configService = new ConfigService($storage, $_ENV);
$logger = new LoggerService($storage);
$normalizer = new JobNormalizer();
$filterService = new JobFilterService();
$deduplicationService = new DeduplicationService();
$reportService = new ReportService();
$mailService = new MailService($logger, $_ENV, $dependenciesAvailable);

$scrapers = $dependenciesAvailable
    ? [
        new UnncScraper($logger),
        new ChinaUniversityJobsScraper($logger),
        new ChinaJobScraper($logger),
        new HiredChinaScraper($logger),
        new JobsCinaScraper($logger),
        new EChinaCitiesScraper($logger),
        new HigherEdJobsScraper($logger),
        new JoobleScraper($logger),
        new ChinaTeachJobsScraper($logger),
    ]
    : [
        new NullScraper($logger, 'unnc', 'Dependencias PHP no instaladas'),
        new NullScraper($logger, 'chinauniversityjobs', 'Dependencias PHP no instaladas'),
        new NullScraper($logger, 'chinajob', 'Dependencias PHP no instaladas'),
        new NullScraper($logger, 'hiredchina', 'Dependencias PHP no instaladas'),
        new NullScraper($logger, 'jobscina', 'Dependencias PHP no instaladas'),
        new NullScraper($logger, 'echinacities', 'Dependencias PHP no instaladas'),
        new NullScraper($logger, 'higheredjobs', 'Dependencias PHP no instaladas'),
        new NullScraper($logger, 'jooble', 'Dependencias PHP no instaladas'),
        new NullScraper($logger, 'chinateachjobs', 'Dependencias PHP no instaladas'),
    ];

$runJobsService = new RunJobsService(
    $configService,
    $storage,
    $logger,
    $normalizer,
    $filterService,
    $deduplicationService,
    $reportService,
    $mailService,
    $scrapers,
    $rejectedJobsStorage
);

return [
    'storage' => $storage,
    'config' => $configService,
    'logger' => $logger,
    'runJobs' => $runJobsService,
    'controller' => new ApiController(
        $configService,
        $storage,
        $logger,
        $runJobsService,
        $normalizer,
        $filterService,
        $deduplicationService,
        $rejectedJobsStorage,
        $dependenciesAvailable
    ),
];
