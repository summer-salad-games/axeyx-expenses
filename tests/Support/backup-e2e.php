<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Enums\Role;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Operations\Backups\BackupPaths;
use App\Operations\Backups\BackupService;
use App\Operations\Backups\BackupTransport;
use App\Operations\Backups\RestoreBackup;
use App\Security\AccountSessions;
use App\Security\AppAuthentication;
use App\Services\AccountService;
use App\Services\TransactionService;
use App\Support\TestIsolation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\LocalBackupTransport;
use Tests\Support\PdfFixture;

$base = dirname(__DIR__, 2);
$project = $argv[2] ?? '';
if (! in_array($project, ['desktop', 'mobile'], true) || ! in_array($argv[1] ?? '', ['prepare', 'restore', 'passwords', 'cleanup'], true)) {
    exit(3);
}
$database = TestIsolation::prepareDatabase($base, 'e2e');
foreach (['APP_ENV' => 'e2e', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8011',
    'APP_CONFIG_CACHE' => $base.'/.data/e2e/config.php', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'ARGON_MEMORY' => '1024', 'ARGON_TIME' => '1'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach (['APP_KEY', 'APP_PREVIOUS_KEYS'] as $key) {
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('e2e') || realpath($database) !== $base.'/.data/e2e/database.sqlite') {
    exit(3);
}
$app->instance(BackupTransport::class, new LocalBackupTransport($base.'/.data/e2e/backup-remote'));
$accountsPath = $base.'/.data/e2e/accounts.json';
$accounts = BackupPaths::readJson($accountsPath);
$contextPath = $base.'/.data/e2e/restore-context-'.$project.'.json';
$action = $argv[1];
try {
    if ($action === 'prepare') {
        $provider = AppAuthentication::make()->recoverable();
        foreach (['admin', 'disabled'] as $kind) {
            $password = Str::password(32);
            $secret = $provider->generateSecret();
            $codes = $provider->generateRecoveryCodes();
            $user = new User;
            $user->forceFill(['name' => 'Restore fixture '.$kind, 'email' => $project.'_restore_'.$kind.'@example.test',
                'password' => Hash::make($password), 'role' => Role::Administrator, 'is_active' => $kind === 'admin',
                'must_change_password' => false, 'onboarding_completed_at' => now(),
                'app_authentication_secret' => $secret, 'app_authentication_recovery_codes' => array_map(fn ($code) => Hash::make($code), $codes)])->save();
            $accounts[$project.'_restore_'.$kind] = ['email' => $user->email, 'password' => $password, 'secret' => $secret, 'recoveryCodes' => $codes];
            if ($kind === 'admin') {
                $actor = $user->fresh();
            }
        }
        auth()->login($actor);
        app(AccountSessions::class)->establish($actor, true);
        $input = $base.'/.data/e2e/private/restore-input.pdf';
        file_put_contents($input, PdfFixture::bytes());
        $expense = app(TransactionService::class)->create($actor, ['type' => 'expense', 'transaction_date' => now('Europe/Luxembourg')->toDateString(),
            'amount' => '1,001', 'category_id' => Category::query()->where('type', 'expense')->where('is_active', true)->firstOrFail()->id,
            'description' => 'Restored expense', 'new_attachments' => [new UploadedFile($input, 'receipt.pdf', test: true)]]);
        $revenue = app(TransactionService::class)->create($actor, ['type' => 'revenue', 'transaction_date' => now('Europe/Luxembourg')->toDateString(),
            'amount' => '3,003', 'category_id' => Category::query()->where('type', 'revenue')->where('is_active', true)->firstOrFail()->id]);
        $id = BackupPaths::id();
        app(BackupService::class)->run($id, fn () => null);
        $later = app(TransactionService::class)->create($actor, ['type' => 'expense', 'transaction_date' => now('Europe/Luxembourg')->toDateString(),
            'amount' => '7,007', 'category_id' => $expense->category_id]);
        BackupPaths::json($contextPath, ['id' => $id, 'expense' => $expense->id, 'revenue' => $revenue->id, 'later' => $later->id,
            'attachment' => $expense->attachments()->sole()->id]);
        BackupPaths::json($accountsPath, $accounts);
    } elseif ($action === 'restore') {
        $context = BackupPaths::readJson($contextPath);
        $restore = app(RestoreBackup::class);
        // The disposable PHP server is idle between requests. Production requires stopped app FPM workers.
        $restore->apply($restore->prepare($context['id']));
    } elseif ($action === 'passwords') {
        foreach (['admin', 'disabled'] as $kind) {
            $key = $project.'_restore_'.$kind;
            $temporary = Str::password(32);
            app(AccountService::class)->changePassword(User::query()->where('email', $accounts[$key]['email'])->firstOrFail(), $temporary, $temporary);
            $accounts[$key]['temporary'] = $temporary;
        }
        BackupPaths::json($accountsPath, $accounts);
    } else {
        if (! is_file($contextPath)) {
            exit(0);
        }
        // Rebuild disposable authentication fixtures for the other Playwright project after the global restore.
        foreach ($accounts as $key => $fixture) {
            $user = User::query()->where('email', $fixture['email'])->first();
            if (! $user) {
                continue;
            }
            $isSetup = str_ends_with($key, '_setup');
            $secret = $fixture['secret'];
            $user->forceFill(['password' => Hash::make($fixture['password']), 'is_active' => ! str_contains($key, '_restore_'),
                'app_authentication_secret' => $secret, 'app_authentication_recovery_codes' => array_map(fn ($code) => Hash::make($code), $fixture['recoveryCodes']),
                'must_change_password' => $isSetup, 'onboarding_completed_at' => $secret ? now() : null, 'auth_version' => $user->auth_version + 1])->save();
        }
        $actor = User::query()->where('email', $accounts[$project.'_restore_admin']['email'])->firstOrFail();
        $actor->forceFill(['is_active' => true])->save();
        auth()->login($actor);
        app(AccountSessions::class)->establish($actor, true);
        $context = BackupPaths::readJson($contextPath);
        foreach ([$context['expense'], $context['revenue'], $context['later']] as $id) {
            if ($transaction = Transaction::query()->find($id)) {
                app(TransactionService::class)->delete($actor, $transaction);
            }
        }
        $actor->forceFill(['is_active' => false])->save();
    }

} catch (Throwable $exception) {
    fwrite(STDERR, 'Restore fixture failed: '.$exception::class."\n");
    exit(1);
}
