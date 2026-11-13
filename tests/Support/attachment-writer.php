<?php

// Disposable concurrency worker: never loads the owner's local database or storage.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Models\Expense;
use App\Models\User;
use App\Security\AccountSessions;
use App\Services\ExpenseService;
use App\Support\TestIsolation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

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
config(['expenses.attachments.count' => $job['count'], 'expenses.attachments.total_bytes' => $job['total']]);
file_put_contents($jobPath.'.ready', 'ready');
try {
    app(ExpenseService::class)->update($actor, Expense::query()->findOrFail($job['expense']), [
        'expense_date' => '2026-11-13', 'amount' => '1', 'category_id' => $job['category'],
        'new_attachments' => [new UploadedFile($job['upload'], 'receipt.png', test: true)],
    ]);
    echo 'accepted';
} catch (ValidationException) {
    echo 'rejected';
}
