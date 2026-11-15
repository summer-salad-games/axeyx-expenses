<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Operations\Backups\BackupPaths;
use App\Operations\Backups\BackupTransport;
use App\Operations\Backups\RestoreBackup;
use App\Support\TestIsolation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Tests\Support\LocalBackupTransport;
use Tests\Support\MemoryMaintenance;

$base = dirname(__DIR__, 2);
$database = TestIsolation::prepareDatabase($base, 'testing');
foreach (['APP_ENV' => 'testing', 'APP_URL' => 'http://127.0.0.1', 'APP_CONFIG_CACHE' => $base.'/.data/testing/config.php',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'ARGON_MEMORY' => '1024', 'ARGON_TIME' => '1'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$jobPath = $argv[1] ?? '';
if (dirname(realpath($jobPath) ?: '') !== $base.'/.data/testing/restore-jobs') {
    exit(3);
}
$job = BackupPaths::readJson($jobPath);
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->useEnvironmentPath($base.'/.data/testing/restore-environment');
$app->instance(BackupTransport::class, new LocalBackupTransport($base.'/.data/testing/backup-remote'));
$app->instance(MaintenanceMode::class, new MemoryMaintenance);
$restore = $app->make(RestoreBackup::class);
$prepared = $restore->prepare($job['id']);
$restore->apply($prepared, function (string $checkpoint) use ($job): void {
    if ($checkpoint === $job['checkpoint']) {
        exit(137); // Simulates an uncatchable stop: PHP does not execute finally on exit.
    }
});
exit(4);
