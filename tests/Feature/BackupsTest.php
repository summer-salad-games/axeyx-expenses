<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Transaction;
use App\Models\User;
use App\Operations\Backups\BackupArchive;
use App\Operations\Backups\BackupDatabase;
use App\Operations\Backups\BackupPaths;
use App\Operations\Backups\BackupService;
use App\Operations\Backups\BackupTransport;
use App\Operations\Backups\RestoreBackup;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\BackupSyncFault;
use Tests\Support\LocalBackupTransport;
use Tests\Support\MemoryMaintenance;
use Tests\Support\TarFixture;
use Tests\TestCase;

class BackupsTest extends TestCase
{
    use DatabaseMigrations;

    private LocalBackupTransport $remote;

    protected function setUp(): void
    {
        parent::setUp();
        $root = base_path('.data/testing/backup-remote');
        BackupPaths::remove($root);
        BackupPaths::remove(config('backups.workspace'));
        $this->remote = new LocalBackupTransport($root);
        $this->app->instance(BackupTransport::class, $this->remote);
        BackupPaths::remove(config('backups.state'));
        BackupPaths::directory(config('backups.state'));
        $environment = BackupPaths::directory(base_path('.data/testing/restore-environment'));
        $this->app->useEnvironmentPath($environment);
        file_put_contents($environment.'/.env', 'APP_KEY='.config('app.key')."\nOTHER_SETTING=preserved\n");
        chmod($environment.'/.env', 0600);
        $this->app->instance(MaintenanceMode::class, new MemoryMaintenance);
    }

    public function test_current_schema_matches_the_trusted_restore_contract(): void
    {
        $service = app(BackupDatabase::class);
        $database = $service->open(config('database.connections.sqlite.database'));
        try {
            $this->assertSame(BackupDatabase::SCHEMA_SHA256, $service->fingerprint($database));
        } finally {
            $database->close();
        }
    }

    private function attachment(): Attachment
    {
        $bytes = 'test receipt bytes';
        $path = 'objects/'.bin2hex(random_bytes(16)).'.pdf';
        Storage::disk('attachments')->put($path, $bytes);

        return Attachment::factory()->create(['transaction_id' => Transaction::factory()->create()->id,
            'path' => $path, 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
    }

    public function test_snapshot_files_and_archive_are_coherent_and_authentication_secrets_are_scrubbed(): void
    {
        $password = 'original-passphrase';
        $user = User::factory()->create(['password' => $password, 'app_authentication_secret' => 'TESTONLYSECRET',
            'app_authentication_recovery_codes' => ['original-recovery-code']]);
        $attachment = $this->attachment();
        $before = $user->fresh()->getRawOriginal();
        $this->remote->onPublish = function (): void {
            $handle = fopen(Storage::disk('local')->path('locks/consistency.lock'), 'c');
            $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'No storage lock during network operations.');
            flock($handle, LOCK_UN);
            fclose($handle);
        };
        $id = BackupPaths::id();
        $manifest = app(BackupService::class)->run($id, fn () => null);
        $this->assertSame(1, $manifest['counts']['attachments']);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $data = BackupPaths::directory(config('backups.workspace').'/inspection');
        app(BackupService::class)->archive('unpack', $data, $this->remote->root.'/'.$id.'/backup.tar.gz', $this->remote->root.'/'.$id.'/manifest.json');
        $this->assertSame('test receipt bytes', file_get_contents($data.'/attachments/'.$attachment->path));
        $database = app(BackupDatabase::class)->open($data.'/database.sqlite');
        $row = $database->querySingle('SELECT * FROM users WHERE id='.$user->id, true);
        $this->assertNull($row['app_authentication_secret']);
        $this->assertNull($row['app_authentication_recovery_codes']);
        $this->assertTrue((bool) $row['must_change_password']);
        $this->assertFalse(Hash::check($password, $row['password']));
        $database->close();
        $this->assertStringNotContainsString($before['app_authentication_secret'], file_get_contents($data.'/database.sqlite'));
        $this->assertDirectoryDoesNotExist(config('backups.workspace').'/'.$id);
    }

    public function test_upload_failure_cleans_staging_and_preserves_source_data(): void
    {
        $attachment = $this->attachment();
        $this->remote->fail = true;
        $id = BackupPaths::id();
        try {
            app(BackupService::class)->run($id, fn () => null);
            $this->fail('Expected upload failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture upload failure.', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist(config('backups.workspace').'/'.$id);
        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        Storage::disk('attachments')->assertExists($attachment->path);
    }

    public function test_restore_replaces_the_data_pair_resets_accounts_and_generates_a_new_key(): void
    {
        $environment = BackupPaths::directory(base_path('.data/testing/restore-environment'));
        $this->app->useEnvironmentPath($environment);
        file_put_contents($environment.'/.env', "APP_KEY=old-key\nOTHER_SETTING=preserved\n");
        $maintenance = new class implements MaintenanceMode
        {
            public bool $down = false;

            public function activate(array $payload): void
            {
                $this->down = true;
            }

            public function deactivate(): void
            {
                $this->down = false;
            }

            public function active(): bool
            {
                return $this->down;
            }

            public function data(): array
            {
                return [];
            }
        };
        $this->app->instance(MaintenanceMode::class, $maintenance);
        $attachment = $this->attachment();
        $id = BackupPaths::id();
        $before = Transaction::query()->count();
        app(BackupService::class)->run($id, fn () => null);
        Transaction::factory()->create();
        $restore = app(RestoreBackup::class);
        $prepared = $restore->prepare($id);
        $restore->apply($prepared);
        $this->assertSame($before, Transaction::query()->count());
        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        Storage::disk('attachments')->assertExists($attachment->path);
        $this->assertStringNotContainsString('APP_KEY=old-key', file_get_contents($environment.'/.env'));
        $this->assertStringContainsString('OTHER_SETTING=preserved', file_get_contents($environment.'/.env'));
        $this->assertFalse($maintenance->active());
        $this->assertSame(0, User::query()->where('must_change_password', false)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.restored']);
    }

    public function test_restore_preserves_a_deployment_environment_symlink_and_runtime_permissions(): void
    {
        $attachment = $this->attachment();
        $environment = app()->environmentFilePath();
        $target = dirname($environment).'/shared.env';
        rename($environment, $target);
        chmod($target, 0640);
        symlink($target, $environment);
        $database = config('database.connections.sqlite.database');
        chmod($database, 0660);
        $id = BackupPaths::id();
        try {
            app(BackupService::class)->run($id, fn () => null);
            $restore = app(RestoreBackup::class);
            $restore->apply($restore->prepare($id));
            clearstatcache();
            $this->assertTrue(is_link($environment));
            $this->assertStringContainsString('APP_KEY=base64:', file_get_contents($target));
            $this->assertSame(0640, fileperms($target) & 07777);
            $this->assertSame(0660, fileperms($database) & 07777);
            $this->assertSame(02770, fileperms(config('filesystems.disks.attachments.root')) & 07777);
            $this->assertSame(0640, fileperms(Storage::disk('attachments')->path($attachment->path)) & 07777);
        } finally {
            unlink($environment);
            rename($target, $environment);
        }
    }

    public function test_restore_requires_offline_acknowledgment_and_explicit_confirmation(): void
    {
        $user = User::factory()->create();
        $before = $user->fresh()->getRawOriginal();
        $environment = file_get_contents(app()->environmentFilePath());
        $id = BackupPaths::id();
        app(BackupService::class)->run($id, fn () => null);
        $this->artisan('backups:restore', ['attempt' => $id])->assertFailed();
        $this->artisan('backups:restore', ['attempt' => $id, '--offline' => true])
            ->expectsConfirmation('Replace current data and reset ALL account passwords, MFA and sessions?', 'no')
            ->expectsOutput('Restore cancelled.')->assertSuccessful();
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame($environment, file_get_contents(app()->environmentFilePath()));
        $this->assertSame([], glob(config('backups.workspace').'/restore-*'));
    }

    public function test_command_status_preserves_the_last_success_after_a_later_failure(): void
    {
        $this->artisan('backups:run', ['--status' => true])->expectsOutput('Last successful backup: none')->assertFailed();
        $this->artisan('backups:run')->assertSuccessful();
        $success = BackupPaths::readJson(config('backups.state').'/last-success.json')['id'];
        $this->artisan('backups:run', ['--status' => true])->expectsOutput('Last successful backup: '.$success)->assertSuccessful();
        $this->remote->fail = true;
        $this->artisan('backups:run')->assertFailed();
        $this->artisan('backups:run', ['--status' => true])->expectsOutput('Last successful backup: '.$success)->assertFailed();
        $this->assertSame($success, BackupPaths::readJson(config('backups.state').'/last-success.json')['id']);
    }

    public function test_verify_only_preserves_current_accounts_and_cleans_downloads(): void
    {
        $user = User::factory()->create(['app_authentication_secret' => 'ORIGINALMFA']);
        $before = $user->fresh()->getRawOriginal();
        $id = BackupPaths::id();
        app(BackupService::class)->run($id, fn () => null);
        $this->artisan('backups:restore', ['attempt' => $id, '--verify-only' => true])->assertSuccessful();
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame([], glob(config('backups.workspace').'/restore-*'));
    }

    public static function interruptionPoints(): array
    {
        return [['database'], ['attachments'], ['environment-preserved'], ['environment'], ['verified']];
    }

    #[DataProvider('interruptionPoints')]
    public function test_fresh_process_can_recover_interrupted_restore_without_losing_environment_paths(string $checkpoint): void
    {
        $attachment = $this->attachment();
        $id = BackupPaths::id();
        app(BackupService::class)->run($id, fn () => null);
        Transaction::factory()->create();
        chmod(app()->environmentFilePath(), 0640);
        $before = file_get_contents(app()->environmentFilePath());
        DB::purge('sqlite');
        $jobs = BackupPaths::directory(base_path('.data/testing/restore-jobs'));
        $job = $jobs.'/job.json';
        BackupPaths::json($job, ['id' => $id, 'checkpoint' => $checkpoint]);
        $process = new Process([PHP_BINARY, base_path('tests/Support/restore-worker.php'), $job], base_path(), timeout: 20);
        $process->run();
        $this->assertSame(137, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileExists(app()->environmentFilePath(), 'The environment must remain present at every interruption point.');
        app(RestoreBackup::class)->recover();
        DB::purge('sqlite');
        $this->assertSame($checkpoint === 'verified' ? 1 : 2, Transaction::query()->count());
        $this->assertSame($checkpoint === 'verified' ? false : true, $before === file_get_contents(app()->environmentFilePath()));
        $this->assertTrue(app(MaintenanceMode::class)->active());
        $this->assertSame(0640, fileperms(app()->environmentFilePath()) & 0777);
        $this->assertFileDoesNotExist(config('backups.state').'/restore.json');
        $this->assertSame([], glob(config('backups.workspace').'/restore-*'));
        Storage::disk('attachments')->assertExists($attachment->path);
    }

    public function test_schema_constraints_cannot_be_removed_by_a_forged_compatible_manifest(): void
    {
        Transaction::factory()->create();
        $id = BackupPaths::id();
        app(BackupService::class)->run($id, fn () => null);
        $archive = $this->remote->root.'/'.$id.'/backup.tar.gz';
        $manifestPath = $this->remote->root.'/'.$id.'/manifest.json';
        $data = BackupPaths::directory(config('backups.workspace').'/forgery');
        app(BackupService::class)->archive('unpack', $data, $archive, $manifestPath);
        $db = app(BackupDatabase::class)->open($data.'/database.sqlite', false);
        $sql = $db->querySingle("SELECT sql FROM sqlite_master WHERE name='transactions'");
        $changed = preg_replace('/,\\s*FOREIGN KEY\\(category_id, type\\) REFERENCES categories\\(id, type\\) ON DELETE RESTRICT ON UPDATE RESTRICT/', '', $sql);
        $this->assertNotSame($sql, $changed);
        $db->exec('PRAGMA foreign_keys=OFF');
        $db->exec(str_replace('CREATE TABLE transactions', 'CREATE TABLE transactions_replacement', $changed));
        $db->exec('INSERT INTO transactions_replacement SELECT * FROM transactions');
        $db->exec('DROP TABLE transactions');
        $db->exec('ALTER TABLE transactions_replacement RENAME TO transactions');
        $db->close();
        $manifest = BackupPaths::readJson($manifestPath);
        $manifest['database'] = BackupPaths::hash($data.'/database.sqlite');
        unlink($archive);
        (new BackupArchive)->pack($data, $archive, $manifest, config('backups'));
        BackupPaths::json($manifestPath, $manifest);
        BackupPaths::json($this->remote->root.'/'.$id.'/complete.json', ['archive' => BackupPaths::hash($archive)]);
        $this->expectException(RuntimeException::class);
        app(RestoreBackup::class)->prepare($id);
    }

    public static function unsafeMembers(): array
    {
        return [['../escape', '0'], ['database.sqlite', '2'], ['database.sqlite', '1'], ['unexpected.txt', '0']];
    }

    #[DataProvider('unsafeMembers')]
    public function test_archive_rejects_unsafe_paths_links_and_unlisted_members(string $name, string $type): void
    {
        $id = BackupPaths::id();
        app(BackupService::class)->run($id, fn () => null);
        $archive = $this->remote->root.'/'.$id.'/backup.tar.gz';
        TarFixture::write($archive, [['name' => $name, 'type' => $type, 'bytes' => 'invalid payload', 'link' => '/etc/passwd']]);
        BackupPaths::json($this->remote->root.'/'.$id.'/complete.json', ['archive' => BackupPaths::hash($archive)]);
        $this->expectException(RuntimeException::class);
        app(RestoreBackup::class)->prepare($id);
    }

    public function test_finalizer_removes_orphan_restore_workspaces_but_preserves_a_pending_journal(): void
    {
        $root = config('backups.workspace');
        $orphan = BackupPaths::directory($root.'/restore-'.BackupPaths::id());
        file_put_contents($orphan.'/financial-data', 'private');
        $pending = BackupPaths::directory($root.'/restore-'.BackupPaths::id());
        BackupPaths::json(config('backups.state').'/restore.json', ['work' => $pending]);
        $process = new Process([PHP_BINARY, base_path('scripts/backup-finalize.php')], base_path(), [
            'BACKUP_STATE_PATH' => config('backups.state'), 'BACKUP_WORKSPACE' => $root,
        ]);
        $process->mustRun();
        $this->assertDirectoryDoesNotExist($orphan);
        $this->assertDirectoryExists($pending);
    }

    public function test_failed_verified_journal_sync_keeps_rollback_until_explicit_recovery(): void
    {
        Transaction::factory()->create();
        $id = BackupPaths::id();
        app(BackupService::class)->run($id, fn () => null);
        Transaction::factory()->create();
        $restore = app(RestoreBackup::class);
        $prepared = $restore->prepare($id);
        BackupSyncFault::$verifiedJournal = true;
        try {
            $restore->apply($prepared);
            $this->fail('Expected synchronization failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cannot synchronize backup metadata.', $exception->getMessage());
        } finally {
            BackupSyncFault::$verifiedJournal = false;
        }
        $journal = BackupPaths::readJson(config('backups.state').'/restore.json');
        $this->assertSame('switching', $journal['phase']);
        $this->assertFileExists($journal['pairs']['database']['old']);
        $restore->recover();
        DB::purge('sqlite');
        $this->assertSame(2, Transaction::query()->count());
    }

    public function test_space_preflight_counts_committed_wal_pages_before_copying_the_database(): void
    {
        DB::statement('PRAGMA wal_autocheckpoint=0');
        Transaction::factory()->count(100)->create(['description' => str_repeat('x', 4000)]);
        $path = config('database.connections.sqlite.database');
        clearstatcache(true, $path);
        $logical = app(BackupDatabase::class)->logicalBytes($path);
        $this->assertGreaterThan(filesize($path), $logical);
        BackupSyncFault::$freeBytes = filesize($path) * 4 + 268435456 + 1;
        $id = BackupPaths::id();
        try {
            app(BackupService::class)->run($id, fn () => null);
            $this->fail('Expected WAL-aware space refusal.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Insufficient staging space', $exception->getMessage());
        } finally {
            BackupSyncFault::$freeBytes = null;
        }
        $this->assertDirectoryDoesNotExist(config('backups.workspace').'/'.$id);
        $this->assertSame(100, Transaction::query()->count());
    }

    public function test_online_snapshot_is_consistent_while_a_real_second_process_commits_wal_writes(): void
    {
        $user = User::factory()->create(['name' => 'Writer 0']);
        Transaction::factory()->count(100)->create(['description' => str_repeat('x', 4000), 'created_by' => $user->id]);
        $ready = base_path('.data/testing/backup-writer.ready');
        @unlink($ready);
        $writer = new Process([PHP_BINARY, base_path('tests/Support/backup-writer.php'), (string) $user->id], base_path(), timeout: 20);
        $writer->start();
        $deadline = microtime(true) + 5;
        while (! file_exists($ready) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($ready);
        $id = BackupPaths::id();
        try {
            app(BackupService::class)->run($id, function (string $stage) use ($writer): void {
                if ($stage === 'snapshot') {
                    $this->assertTrue($writer->isRunning());
                }
            });
            $data = BackupPaths::directory(config('backups.workspace').'/concurrent-inspection');
            app(BackupService::class)->archive('unpack', $data, $this->remote->root.'/'.$id.'/backup.tar.gz', $this->remote->root.'/'.$id.'/manifest.json');
            $db = app(BackupDatabase::class)->open($data.'/database.sqlite');
            $name = $db->querySingle('SELECT name FROM users WHERE id='.$user->id);
            $counter = $db->querySingle("SELECT MAX(CAST(subject_id AS INTEGER)) FROM audit_logs WHERE action='fixture.counter'");
            $this->assertSame('Writer '.$counter, $name);
            $db->close();
            $writer->wait();
            $this->assertTrue($writer->isSuccessful(), $writer->getErrorOutput());
        } finally {
            if ($writer->isRunning()) {
                $writer->stop();
            }
            @unlink($ready);
        }
    }

    public function test_restore_works_with_a_corrupt_current_database_and_no_old_key(): void
    {
        $user = User::factory()->create(['app_authentication_secret' => 'ORIGINALMFA']);
        $id = BackupPaths::id();
        app(BackupService::class)->run($id, fn () => null);
        DB::purge('sqlite');
        file_put_contents(config('database.connections.sqlite.database'), 'corrupt database fixture');
        config(['app.key' => '']);
        $restore = app(RestoreBackup::class);
        $restore->apply($restore->prepare($id));
        DB::purge('sqlite');
        $restored = User::query()->findOrFail($user->id);
        $this->assertNull($restored->app_authentication_secret);
        $this->assertTrue($restored->must_change_password);
        $this->assertStringContainsString('APP_KEY=base64:', file_get_contents(app()->environmentFilePath()));
    }
}
