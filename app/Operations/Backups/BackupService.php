<?php

declare(strict_types=1);

namespace App\Operations\Backups;

use App\Services\AttachmentFiles;
use App\Support\StorageConsistencyLock;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Process\Process;

final class BackupService
{
    public function __construct(private BackupDatabase $database, private BackupTransport $transport) {}

    public function run(string $id, callable $stage): array
    {
        if (! BackupPaths::validId($id)) {
            throw new RuntimeException('Invalid backup attempt ID.');
        }
        $work = BackupPaths::directory(config('backups.workspace')).'/'.$id;
        if (file_exists($work) || is_link($work)) {
            throw new RuntimeException('Backup attempt workspace already exists.');
        }
        BackupPaths::directory($work.'/data/attachments/objects');
        BackupPaths::directory($work.'/upload');
        try {
            $stage('cleanup');
            if (Artisan::call('attachments:reconcile', ['--delete' => true]) !== 0) {
                throw new RuntimeException('Attachment reconciliation failed.');
            }
            $source = config('database.connections.sqlite.database');
            $required = $this->database->metadata($source);
            $dataBytes = $this->database->logicalBytes($source) + array_sum(array_column($required['attachments'], 'bytes'));
            if ($dataBytes > config('backups.expanded_bytes') || disk_free_space($work) < $dataBytes * 4 + 268435456) {
                throw new RuntimeException('Insufficient staging space or backup exceeds the expanded limit.');
            }
            $stage('snapshot');
            $snapshot = $work.'/data/database.sqlite';
            app(StorageConsistencyLock::class)->run(function () use ($snapshot, $work): void {
                $this->database->snapshot(config('database.connections.sqlite.database'), $snapshot);
                $files = app(AttachmentFiles::class);
                $metadata = $this->database->metadata($snapshot);
                $bytes = filesize($snapshot) + array_sum(array_column($metadata['attachments'], 'bytes'));
                if ($bytes > config('backups.expanded_bytes') || disk_free_space($work) < $bytes * 3 + 268435456) {
                    throw new RuntimeException('Insufficient disk space after the consistent snapshot.');
                }
                foreach ($metadata['attachments'] as $file) {
                    $relative = substr($file['path'], strlen('attachments/'));
                    $source = $files->readPath($relative);
                    $target = $work.'/data/'.$file['path'];
                    if (! $source || BackupPaths::hash($source) !== array_intersect_key($file, ['bytes' => 1, 'sha256' => 1])) {
                        throw new RuntimeException('Referenced attachment is missing, altered or cannot be staged.');
                    }
                    BackupPaths::copy($source, $target);
                    if (BackupPaths::hash($target) !== array_intersect_key($file, ['bytes' => 1, 'sha256' => 1])) {
                        throw new RuntimeException('Staged attachment verification failed.');
                    }
                }
            });
            $stage('sanitize');
            $this->database->sanitize($snapshot);
            $metadata = $this->database->metadata($snapshot);
            $manifest = ['format' => 1, 'id' => $id, 'created_at' => gmdate(DATE_ATOM),
                'schema' => BackupManifest::schema(), 'release' => config('backups.release'), 'account_reset' => true,
                'database' => BackupPaths::hash($snapshot), ...$metadata];
            $manifestPath = $work.'/upload/manifest.json';
            BackupPaths::json($manifestPath, $manifest);
            $stage('archive');
            $this->archive('pack', $work.'/data', $work.'/upload/backup.tar.gz', $manifestPath);
            $stage('upload');
            $this->transport->publish($id, $work.'/upload');
            $stage('retention');
            $this->transport->prune($id);

            return $manifest;
        } finally {
            BackupPaths::remove($work);
        }
    }

    public function archive(string $operation, string $data, string $archive, string $manifest): void
    {
        $limits = array_intersect_key(config('backups'), array_flip(['archive_bytes', 'expanded_bytes', 'entries']));
        $temporary = BackupPaths::directory(dirname(dirname($manifest)).'/parser-tmp');
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=256M', '-d', 'sys_temp_dir='.$temporary, base_path('scripts/backup-archive.php'),
            $operation, $data, $archive, $manifest, json_encode($limits, JSON_THROW_ON_ERROR)], env: ['TMPDIR' => $temporary, 'TMP' => $temporary, 'TEMP' => $temporary], timeout: config('backups.timeout'));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Backup archive validation or staging failed.');
        }
    }
}
