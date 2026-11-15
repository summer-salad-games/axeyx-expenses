<?php

declare(strict_types=1);

namespace App\Operations\Backups;

use RuntimeException;
use Symfony\Component\Process\Process;

final class RcloneBackupTransport implements BackupTransport
{
    private array $settings;

    public function __construct()
    {
        $this->settings = config('backups');
    }

    private function remote(string $suffix = ''): string
    {
        $remote = $this->settings['remote'];
        if (! preg_match('~\A[a-zA-Z0-9_-]+:[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*\z~', $remote)) {
            throw new RuntimeException('Configure a dedicated non-root BACKUP_REMOTE folder.');
        }

        return $remote.($suffix === '' ? '' : '/'.$suffix);
    }

    private function run(array $arguments): string
    {
        $config = $this->settings['rclone_config'];
        if ($config === '' || is_link($config) || ! is_file($config) || (fileperms($config) & 0077) !== 0) {
            throw new RuntimeException('Rclone requires a private configuration file (0600).');
        }
        $temporary = BackupPaths::directory(rtrim(config('backups.workspace'), '/').'/rclone-tmp');
        $process = new Process([$this->settings['rclone'], ...$arguments, '--config', $config,
            '--temp-dir', $temporary, '--retries', '2', '--low-level-retries', '2', '--contimeout', '15s', '--timeout', '120s'],
            env: ['TMPDIR' => $temporary, 'TMP' => $temporary, 'TEMP' => $temporary], timeout: $this->settings['timeout']);
        $output = '';
        $process->disableOutput();
        try {
            $process->run(function (string $type, string $buffer) use (&$output): void {
                if ($type !== Process::OUT) {
                    return;
                }
                if (strlen($output) + strlen($buffer) > 16_777_216) {
                    throw new RuntimeException('Remote metadata exceeds its limit.');
                }
                $output .= $buffer;
            });
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Google Drive operation failed.');
            }

            return $output;
        } catch (\Throwable) {
            // Never expose provider output, command configuration or credential-bearing exceptions.
            throw new RuntimeException('Google Drive operation failed or exceeded its time/output limit.');
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
            BackupPaths::remove($temporary);
        }
    }

    public function publish(string $id, string $directory): void
    {
        $this->assertId($id);
        $destination = $this->remote($id);
        $this->run(['copy', $directory, $destination]);
        // --download prevents a backend from silently falling back to size-only checks.
        $this->run(['check', $directory, $destination, '--download', '--one-way']);
        $marker = ['format' => 1, 'id' => $id, 'archive' => BackupPaths::hash($directory.'/backup.tar.gz'),
            'manifest' => BackupPaths::hash($directory.'/manifest.json')];
        BackupPaths::json($directory.'/complete.json', $marker);
        $this->run(['copyto', $directory.'/complete.json', $destination.'/complete.json']);
        if ($this->marker($id) !== $marker) {
            throw new RuntimeException('Backup completion marker read-back failed.');
        }
    }

    private function assertId(string $id): void
    {
        if (! BackupPaths::validId($id)) {
            throw new RuntimeException('Invalid backup attempt ID.');
        }
    }

    private function marker(string $id): array
    {
        $this->assertId($id);
        $marker = json_decode($this->run(['cat', $this->remote($id.'/complete.json')]), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($marker) || ($marker['id'] ?? null) !== $id || ($marker['format'] ?? null) !== 1) {
            throw new RuntimeException('Invalid backup completion marker.');
        }
        foreach (['archive' => $this->settings['archive_bytes'], 'manifest' => 16_777_216] as $key => $maximum) {
            $file = $marker[$key] ?? [];
            if (! is_int($file['bytes'] ?? null) || $file['bytes'] < 1 || $file['bytes'] > $maximum
                || ! is_string($file['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $file['sha256'])) {
                throw new RuntimeException('Invalid remote backup checksum or size.');
            }
        }

        return $marker;
    }

    private function entries(string $remote, bool $directories): array
    {
        $entries = json_decode($this->run(['lsjson', $remote, $directories ? '--dirs-only' : '--files-only', '--max-depth', '1']), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new RuntimeException('Invalid remote backup listing.');
        }

        return $entries;
    }

    public function attempts(): array
    {
        $ids = [];
        foreach ($this->entries($this->remote(), true) as $entry) {
            $id = $entry['Name'] ?? '';
            if (! is_string($id) || ! BackupPaths::validId($id)) {
                continue;
            }
            $files = $this->entries($this->remote($id), false);
            if (in_array('complete.json', array_column($files, 'Name'), true)) {
                $this->marker($id);
                $ids[] = $id;
            }
        }
        rsort($ids, SORT_STRING);

        return $ids;
    }

    public function download(string $id, string $directory): array
    {
        $marker = $this->marker($id);
        $listing = $this->entries($this->remote($id), false);
        $sizes = array_column($listing, 'Size', 'Name');
        foreach (['manifest.json' => 'manifest', 'backup.tar.gz' => 'archive'] as $name => $key) {
            if (($sizes[$name] ?? null) !== $marker[$key]['bytes']) {
                throw new RuntimeException('Remote backup size changed or is incomplete.');
            }
            if (disk_free_space($directory) < $marker[$key]['bytes'] + 104857600) {
                throw new RuntimeException('Insufficient disk space for backup download.');
            }
            $path = $directory.'/'.$name;
            $this->run(['copyto', $this->remote($id.'/'.$name), $path,
                '--max-transfer', (string) $marker[$key]['bytes'], '--cutoff-mode', 'HARD']);
            chmod($path, 0600);
            if (BackupPaths::hash($path) !== $marker[$key]) {
                throw new RuntimeException('Downloaded backup checksum mismatch.');
            }
        }

        return BackupPaths::readJson($directory.'/manifest.json');
    }

    public function prune(string $keepId): void
    {
        $this->assertId($keepId);
        $ids = $this->attempts();
        $keep = array_unique([...array_slice($ids, 0, max(1, $this->settings['retain'])), $keepId]);
        foreach (array_diff($ids, $keep) as $id) {
            $this->run(['purge', $this->remote($id), '--drive-use-trash=false']);
        }
        // Only our old, uncompleted attempt directories can be pruned as abandoned uploads.
        $cutoff = gmdate('Ymd\THis\Z', time() - max(1, $this->settings['incomplete_days']) * 86400);
        foreach ($this->entries($this->remote(), true) as $entry) {
            $id = $entry['Name'] ?? '';
            if (is_string($id) && BackupPaths::validId($id) && substr($id, 0, 16) < $cutoff && ! in_array($id, $ids, true)) {
                $this->run(['purge', $this->remote($id), '--drive-use-trash=false']);
            }
        }
    }
}
