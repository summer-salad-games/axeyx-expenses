<?php

namespace App\Console\Commands;

use App\Operations\Backups\BackupPaths;
use App\Operations\Backups\BackupTransport;
use App\Operations\Backups\RestoreBackup as RestoreService;
use Illuminate\Console\Command;
use Throwable;

final class RestoreBackup extends Command
{
    protected $signature = 'backups:restore {attempt? : Verified backup attempt ID} {--list : List completed remote backups}
        {--verify-only : Download and verify without changing application data} {--recover : Recover an interrupted local restore}
        {--offline : Confirm that app PHP workers and other CLI writers are stopped}';

    protected $description = 'Verify and restore a complete backup, resetting all account passwords and MFA';

    public function handle(RestoreService $restore, BackupTransport $transport): int
    {
        $prepared = null;
        $lock = null;
        try {
            $state = BackupPaths::directory(config('backups.state'));
            $lock = fopen($state.'/run.lock', 'c');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
                $this->error('A backup or restore is already running.');

                return self::FAILURE;
            }
            chmod($state.'/run.lock', 0600);
            if ($this->option('list')) {
                foreach ($transport->attempts() as $id) {
                    $this->line($id);
                }

                return self::SUCCESS;
            }
            if ($this->option('recover')) {
                if (! $this->option('offline') || ! $this->confirm('Recover the interrupted restore while all app writers are stopped?', false)) {
                    $this->error('Recovery requires --offline and explicit confirmation.');

                    return self::FAILURE;
                }
                $restore->recover();
                $this->info('Recovery completed. Maintenance mode remains active; verify the application before reopening.');

                return self::SUCCESS;
            }
            $id = (string) $this->argument('attempt');
            $prepared = $restore->prepare($id);
            $this->info('Verified backup: '.$id);
            $this->table(['Table', 'Records'], collect($prepared['manifest']['counts'])->map(fn ($count, $table) => [$table, $count])->values());
            if ($this->option('verify-only')) {
                return self::SUCCESS;
            }
            if (! $this->option('offline')) {
                $this->error('Stop only this app PHP workers and all CLI writers, then rerun with --offline.');

                return self::FAILURE;
            }
            if (! $this->confirm('Replace current data and reset ALL account passwords, MFA and sessions?', false)) {
                $this->info('Restore cancelled.');

                return self::SUCCESS;
            }
            $restore->apply($prepared);
            $prepared = null;
            $this->info('Restore completed. Set individual temporary passwords with accounts:password before granting access. Restart only the app PHP workers.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Restore did not finish. Keep app workers stopped; use --recover if a recovery journal remains.');

            return self::FAILURE;
        } finally {
            if ($prepared) {
                BackupPaths::remove($prepared['work']);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
