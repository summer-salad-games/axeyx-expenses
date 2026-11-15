<?php

declare(strict_types=1);

namespace App\Operations\Backups;

use App\Support\StorageConsistencyLock;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class RestoreBackup
{
    public function __construct(private BackupTransport $transport, private BackupService $backups, private BackupDatabase $database,
        private MaintenanceMode $maintenance) {}

    public function prepare(string $id): array
    {
        if (! BackupPaths::validId($id)) {
            throw new RuntimeException('Invalid backup attempt ID.');
        }
        $work = BackupPaths::directory(config('backups.workspace')).'/restore-'.BackupPaths::id();
        BackupPaths::directory($work.'/download');
        try {
            $manifest = $this->transport->download($id, $work.'/download');
            if (($manifest['id'] ?? null) !== $id) {
                throw new RuntimeException('Backup ID does not match the selected attempt.');
            }
            $files = BackupManifest::validate($manifest, config('backups'));
            $bytes = array_sum(array_column($files, 'bytes'));
            if (disk_free_space($work) < $bytes * 2 + 104857600) {
                throw new RuntimeException('Insufficient free space for restore staging and verification.');
            }
            $this->backups->archive('unpack', $work.'/data', $work.'/download/backup.tar.gz', $work.'/download/manifest.json');
            $metadata = $this->database->metadata($work.'/data/database.sqlite');
            if (($manifest['counts'] ?? null) !== $metadata['counts']) {
                throw new RuntimeException('Restored database counts do not match the manifest.');
            }
            // Enforce reset even if an archive was made by an older/incorrect backup producer.
            $this->database->sanitize($work.'/data/database.sqlite');

            return ['work' => $work, 'manifest' => $manifest];
        } catch (Throwable $exception) {
            BackupPaths::remove($work);
            throw $exception;
        }
    }

    private function targets(bool $recovering = false): array
    {
        $database = config('database.connections.sqlite.database');
        $attachments = config('filesystems.disks.attachments.root');
        $environment = realpath(app()->environmentFilePath()) ?: app()->environmentFilePath();
        if (is_link($database) || is_link($attachments) || is_link($environment) || (! $recovering && ! is_file($environment))
            || realpath(dirname($database)) !== dirname($database) || (file_exists($attachments) ? realpath($attachments) !== $attachments : ! $recovering)
            || ! is_writable(dirname($database)) || ! is_writable(dirname($attachments)) || ! is_writable(dirname($environment))) {
            throw new RuntimeException('Restore targets must be writable, private, real paths with an existing environment file.');
        }
        $targets = ['database' => $database, 'attachments' => $attachments, 'environment' => $environment];
        foreach ([$database, $environment] as $file) {
            if (file_exists($file) && ! is_file($file)) {
                throw new RuntimeException('Unsafe restore target.');
            }
        }
        foreach ([config('backups.workspace'), config('backups.state'), config('session.files'), config('filesystems.disks.local.root')] as $path) {
            if (rtrim($path, '/') === $attachments || str_starts_with($path.'/', $attachments.'/')) {
                throw new RuntimeException('Attachment storage must not contain restore state, sessions or coordination files.');
            }
        }
        if ($attachments === '/' || str_starts_with($database, $attachments.'/') || str_starts_with($environment, $attachments.'/')) {
            throw new RuntimeException('Restore targets overlap.');
        }

        return $targets;
    }

    public function apply(array $prepared, ?callable $checkpoint = null): void
    {
        $state = BackupPaths::directory(config('backups.state'));
        $journal = $state.'/restore.json';
        if (file_exists($journal)) {
            throw new RuntimeException('An interrupted restore must be recovered first.');
        }
        $targets = $this->targets();
        $operation = BackupPaths::id();
        $pairs = [];
        foreach ($targets as $key => $path) {
            $pairs[$key] = ['live' => $path, 'old' => $path.'.rollback-'.$operation, 'new' => $path.'.restore-'.$operation,
                'existed' => file_exists($path)];
            $stat = stat(file_exists($path) ? $path : dirname($path));
            $pairs[$key]['uid'] = $stat['uid'];
            $pairs[$key]['gid'] = $stat['gid'];
            $pairs[$key]['mode'] = $key === 'environment' ? ($stat['mode'] & 0640)
                : ($key === 'attachments' ? 02770 : ($stat['mode'] & 0660 ?: 0640));
        }
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            $path = $targets['database'].$suffix;
            $pairs['sqlite'.$suffix] = ['live' => $path, 'old' => $path.'.rollback-'.$operation, 'new' => null, 'existed' => file_exists($path)];
        }
        $record = ['operation' => $operation, 'phase' => 'preparing', 'work' => $prepared['work'], 'pairs' => $pairs];
        BackupPaths::json($journal, $record);
        try {
            $database = $pairs['database']['new'];
            BackupPaths::copy($prepared['work'].'/data/database.sqlite', $database);
            BackupPaths::directory($pairs['attachments']['new'].'/objects');
            foreach ($prepared['manifest']['attachments'] as $file) {
                $relative = substr($file['path'], strlen('attachments/'));
                $destination = $pairs['attachments']['new'].'/'.$relative;
                BackupPaths::copy($prepared['work'].'/data/'.$file['path'], $destination);
                if (BackupPaths::hash($destination) !== array_intersect_key($file, ['bytes' => 1, 'sha256' => 1])) {
                    throw new RuntimeException('Cannot stage restored attachment.');
                }
                chmod($destination, 0600);
            }
            $key = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
            $environment = file_get_contents($targets['environment']);
            foreach (['APP_KEY' => $key, 'APP_PREVIOUS_KEYS' => ''] as $name => $value) {
                $pattern = '/^(?:export\s+)?'.preg_quote($name, '/').'\s*=.*$/m';
                $environment = preg_match($pattern, $environment)
                    ? preg_replace_callback($pattern, fn () => $name.'='.$value, $environment)
                    : rtrim($environment)."\n".$name.'='.$value."\n";
            }
            BackupPaths::write($pairs['environment']['new'], $environment);
            foreach (['database', 'environment'] as $name) {
                $this->permissions($pairs[$name]['new'], $pairs[$name], $pairs[$name]['mode']);
            }
            $this->permissions($pairs['attachments']['new'], $pairs['attachments'], 02770);
            $this->permissions($pairs['attachments']['new'].'/objects', $pairs['attachments'], 02770);
            foreach (glob($pairs['attachments']['new'].'/objects/*') as $file) {
                $this->permissions($file, $pairs['attachments'], 0640);
            }
            BackupPaths::syncDirectory($pairs['attachments']['new'].'/objects');
            BackupPaths::syncDirectory($pairs['attachments']['new']);
            $this->maintenance->activate(['time' => time(), 'status' => 503, 'retry' => 60]);
            app(StorageConsistencyLock::class)->run(function () use (&$record, $journal, $checkpoint, $prepared): void {
                DB::purge('sqlite');
                foreach ($record['pairs'] as &$pair) {
                    $pair['existed'] = file_exists($pair['live']);
                }
                unset($pair);
                $record['phase'] = 'switching';
                BackupPaths::json($journal, $record);
                foreach ($record['pairs'] as $name => $pair) {
                    if ($pair['existed']) {
                        if ($name === 'environment') {
                            // Keep live .env present throughout: fresh-process recovery needs its paths.
                            BackupPaths::copy($pair['live'], $pair['old'].'.tmp');
                            $this->permissions($pair['old'].'.tmp', $pair, $pair['mode']);
                            BackupPaths::rename($pair['old'].'.tmp', $pair['old']);
                            ($checkpoint ?? fn () => null)('environment-preserved');
                        } else {
                            BackupPaths::rename($pair['live'], $pair['old']);
                        }
                    }
                    if ($pair['new']) {
                        BackupPaths::rename($pair['new'], $pair['live']);
                    }
                    ($checkpoint ?? fn () => null)($name);
                }
                $live = $record['pairs']['database']['live'];
                $this->database->metadata($live);
                $this->clearTransientState();
                $db = $this->database->open($live, false);
                try {
                    $statement = $db->prepare("INSERT INTO audit_logs (actor_kind, action, subject_type, subject_id, metadata, created_at)
                        VALUES ('operator', 'backup.restored', 'backup', :id, '{}', :time)");
                    $statement->bindValue(':id', $prepared['manifest']['id']);
                    $statement->bindValue(':time', gmdate('Y-m-d H:i:s'));
                    $statement->execute();
                } finally {
                    $db->close();
                }
                $record['phase'] = 'verified';
                BackupPaths::json($journal, $record);
                ($checkpoint ?? fn () => null)('verified');
            });
            $this->discardRollback($record);
            BackupPaths::remove($journal);
            BackupPaths::syncDirectory(dirname($journal));
            $this->maintenance->deactivate();
        } catch (Throwable $exception) {
            if ($record['phase'] !== 'verified') {
                $this->rollback($record);
                BackupPaths::remove($journal);
                BackupPaths::syncDirectory(dirname($journal));
            }
            // Keep maintenance active on failure; only the operator may reopen the application.
            throw $exception;
        } finally {
            BackupPaths::remove($prepared['work']);
        }
    }

    private function permissions(string $path, array $owner, int $mode): void
    {
        if (is_link($path) || (fileowner($path) !== $owner['uid'] && ! chown($path, $owner['uid']))
            || (filegroup($path) !== $owner['gid'] && ! chgrp($path, $owner['gid'])) || ! chmod($path, $mode)) {
            throw new RuntimeException('Restore operator cannot preserve runtime ownership and permissions.');
        }
        clearstatcache(true, $path);
        if (fileowner($path) !== $owner['uid'] || filegroup($path) !== $owner['gid'] || (fileperms($path) & 07777) !== $mode) {
            throw new RuntimeException('Restored runtime permissions could not be verified.');
        }
        is_dir($path) ? BackupPaths::syncDirectory($path) : BackupPaths::syncFile($path);
    }

    private function clearTransientState(): void
    {
        foreach ([config('session.files'), config('cache.stores.file.path'), config('cache.stores.file.lock_path')] as $path) {
            if (! is_string($path) || $path === '/' || is_link($path) || realpath($path) !== $path) {
                throw new RuntimeException('Unsafe session or cache directory.');
            }
            foreach (new \FilesystemIterator($path) as $entry) {
                BackupPaths::remove($entry->getPathname());
            }
        }
        $cache = app()->getCachedConfigPath();
        if (is_link($cache) || (file_exists($cache) && ! unlink($cache))) {
            throw new RuntimeException('Cannot clear cached application configuration.');
        }
    }

    private function discardRollback(array $record): void
    {
        foreach ($record['pairs'] as $pair) {
            BackupPaths::remove($pair['old']);
            BackupPaths::remove($pair['old'].'.tmp');
            if ($pair['new']) {
                BackupPaths::remove($pair['new']);
            }
            BackupPaths::syncDirectory(dirname($pair['live']));
        }
    }

    private function rollback(array $record): void
    {
        DB::purge('sqlite');
        foreach (array_reverse($record['pairs']) as $pair) {
            if (file_exists($pair['old']) || is_link($pair['old'])) {
                if (is_dir($pair['live']) && ! is_link($pair['live'])) {
                    BackupPaths::remove($pair['live']);
                }
                BackupPaths::rename($pair['old'], $pair['live']);
            } elseif (! $pair['existed'] && $record['phase'] !== 'preparing') {
                BackupPaths::remove($pair['live']);
            }
            BackupPaths::remove($pair['old'].'.tmp');
            if ($pair['new']) {
                BackupPaths::remove($pair['new']);
            }
            BackupPaths::syncDirectory(dirname($pair['live']));
        }
    }

    public function recover(): void
    {
        $journal = BackupPaths::directory(config('backups.state')).'/restore.json';
        $record = BackupPaths::readJson($journal, 32768);
        $targets = $this->targets(true);
        $operation = $record['operation'] ?? '';
        if (! is_string($operation) || ! BackupPaths::validId($operation)
            || ! in_array($record['phase'] ?? '', ['preparing', 'switching', 'verified'], true)) {
            throw new RuntimeException('Invalid restore recovery journal.');
        }
        $work = $record['work'] ?? '';
        $prefix = rtrim(config('backups.workspace'), '/').'/restore-';
        if (! is_string($work) || ! str_starts_with($work, $prefix) || ! BackupPaths::validId(substr($work, strlen($prefix)))) {
            throw new RuntimeException('Invalid restore recovery workspace.');
        }
        $expected = [...$targets, 'sqlite-wal' => $targets['database'].'-wal', 'sqlite-shm' => $targets['database'].'-shm', 'sqlite-journal' => $targets['database'].'-journal'];
        if (array_keys($record['pairs'] ?? []) !== array_keys($expected)) {
            throw new RuntimeException('Restore journal does not match current configuration.');
        }
        foreach ($expected as $name => $live) {
            $pair = $record['pairs'][$name];
            if ($pair['live'] !== $live || $pair['old'] !== $live.'.rollback-'.$operation
                || $pair['new'] !== (str_starts_with($name, 'sqlite-') ? null : $live.'.restore-'.$operation)
                || ! is_bool($pair['existed'])) {
                throw new RuntimeException('Unsafe restore recovery paths.');
            }
        }
        $this->maintenance->activate(['time' => time(), 'status' => 503]);
        app(StorageConsistencyLock::class)->run(function () use ($record): void {
            if ($record['phase'] === 'verified') {
                $this->discardRollback($record);
            } else {
                $this->rollback($record);
            }
        });
        unlink($journal);
        BackupPaths::syncDirectory(dirname($journal));
        BackupPaths::remove($work);
    }
}
