<?php

namespace App\Console\Commands;

use App\Operations\Backups\BackupAttempt;
use App\Operations\Backups\BackupPaths;
use App\Operations\Backups\BackupService;
use Illuminate\Console\Command;
use Throwable;

final class RunBackup extends Command
{
    protected $signature = 'backups:run {--attempt= : Attempt ID reserved by the systemd wrapper} {--status : Show latest local attempt and last successful backup}';

    protected $description = 'Create, verify and upload a full recovery backup; clean staging and apply retention';

    public function handle(BackupService $backups): int
    {
        $root = BackupPaths::directory(config('backups.state'));
        if ($this->option('status')) {
            $paths = glob($root.'/attempts/*.json');
            rsort($paths, SORT_STRING);
            $latest = $paths ? BackupPaths::readJson($paths[0], 8192) : null;
            $last = is_file($root.'/last-success.json') ? BackupPaths::readJson($root.'/last-success.json', 8192) : null;
            $this->line('Latest attempt: '.($latest['id'] ?? 'none'));
            $this->line('Status: '.($latest['status'] ?? 'none').'; stage: '.($latest['stage'] ?? 'none'));
            $this->line('Last successful backup: '.($last['id'] ?? 'none'));

            return $latest && $latest['status'] === 'succeeded' ? self::SUCCESS : self::FAILURE;
        }
        $lock = fopen($root.'/run.lock', 'c');
        chmod($root.'/run.lock', 0600);
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('A backup is already running.');

            return self::FAILURE;
        }
        $id = $this->option('attempt') ?: BackupPaths::id();
        if (! is_string($id) || ! BackupPaths::validId($id)) {
            $this->error('Invalid backup attempt ID.');
            flock($lock, LOCK_UN);
            fclose($lock);

            return self::FAILURE;
        }
        $attempt = new BackupAttempt($root);
        try {
            if (! $this->option('attempt')) {
                $attempt->start($id);
            }
            if (is_file($root.'/restore.json')) {
                throw new \RuntimeException('An interrupted restore must be recovered first.');
            }
            $backups->run($id, fn (string $stage) => $attempt->stage($id, $stage));
            $attempt->finish($id, true);
            $this->info('Backup verified: '.$id);

            return self::SUCCESS;
        } catch (Throwable) {
            if (BackupPaths::validId($id) && is_file($root.'/attempts/'.$id.'.json')) {
                $attempt->finish($id, false);
            }
            $this->error('Backup failed: '.$id.'. Check the private attempt status; no successful backup was removed before verification.');

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
