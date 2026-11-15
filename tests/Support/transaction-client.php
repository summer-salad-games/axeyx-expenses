<?php

// Disposable concurrency worker: never loads the owner's local database or storage.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Models\User;
use App\Security\AccountSessions;
use App\Services\TransactionService;
use App\Services\TransactionStatistics;
use App\Support\TestIsolation;
use Illuminate\Contracts\Console\Kernel;

$base = dirname(__DIR__, 2);
$database = TestIsolation::prepareDatabase($base, 'testing');
foreach ([
    'APP_ENV' => 'testing', 'APP_URL' => 'http://127.0.0.1',
    'APP_CONFIG_CACHE' => $base.'/.data/testing/config.php',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'DB_DATABASE' => $database, 'DB_URL' => '', 'SESSION_DRIVER' => 'array',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$jobPath = $argv[1];
if (dirname(realpath($jobPath) ?: '') !== $base.'/.data/testing/private/test-inputs') {
    exit(3);
}
$job = json_decode(file_get_contents($jobPath), true, flags: JSON_THROW_ON_ERROR);
$actor = User::query()->findOrFail($job['actor']);
auth()->login($actor);
app(AccountSessions::class)->establish($actor, true);
file_put_contents($jobPath.'.ready', 'ready');
$deadline = microtime(true) + 25;
while (! file_exists($job['barrier'])) {
    if (microtime(true) > $deadline) {
        exit(4);
    }
    usleep(10000);
}
$transaction = app(TransactionService::class)->create($actor, [
    'type' => $job['type'], 'transaction_date' => '2026-11-15', 'amount' => '1,001', 'category_id' => $job['category'],
]);
$report = app(TransactionStatistics::class)->report($actor, ['preset' => 'custom', 'from' => '2026-11-15', 'until' => '2026-11-15']);
if ((int) $report['net_millimes'] !== (int) $report['revenue_millimes'] - (int) $report['expense_millimes'] || $report['count'] < 1) {
    exit(5);
}
echo $transaction->id;
