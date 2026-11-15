<?php

// systemd ExecStopPost: mark interrupted attempts and remove only their private staging.
require dirname(__DIR__).'/app/Operations/Backups/BackupPaths.php';
require dirname(__DIR__).'/app/Operations/Backups/BackupAttempt.php';

use App\Operations\Backups\BackupAttempt;
use App\Operations\Backups\BackupPaths;

try {
    $state = getenv('BACKUP_STATE_PATH') ?: throw new RuntimeException;
    $work = getenv('BACKUP_WORKSPACE') ?: throw new RuntimeException;
    BackupPaths::directory($state);
    $lock = fopen($state.'/wrapper.lock', 'c');
    if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException;
    }
    $runLock = fopen($state.'/run.lock', 'c');
    if (! $runLock || ! flock($runLock, LOCK_EX | LOCK_NB)) {
        fwrite(STDOUT, "Backup cleanup deferred while another operation is running.\n");
        exit(0);
    }
    // A failed previous boot may leave older workspaces. Completed/failed attempts are safe to clean.
    BackupPaths::directory($work);
    foreach (glob($state.'/attempts/*.json') as $file) {
        $data = BackupPaths::readJson($file);
        $id = $data['id'] ?? '';
        if (is_string($id) && BackupPaths::validId($id) && basename($file) === $id.'.json' && ($data['status'] ?? '') === 'running') {
            // Free temporary data before trying to persist status on a full filesystem.
            BackupPaths::remove($work.'/'.$id);
            // Both orchestration locks are free: no live operation owns this attempt.
            (new BackupAttempt($state))->finish($id, false);
            $data['status'] = 'failed';
        }
        if (is_string($id) && BackupPaths::validId($id) && in_array($data['status'] ?? '', ['failed', 'succeeded'], true)) {
            BackupPaths::remove($work.'/'.$id);
        }
    }
    BackupPaths::remove($work.'/rclone-tmp');
    $pending = null;
    if (is_file($state.'/restore.json')) {
        $pending = BackupPaths::readJson($state.'/restore.json', 32768)['work'] ?? null;
        $prefix = $work.'/restore-';
        if (! is_string($pending) || ! str_starts_with($pending, $prefix) || ! BackupPaths::validId(substr($pending, strlen($prefix)))) {
            throw new RuntimeException('Invalid pending restore workspace.');
        }
    }
    foreach (glob($work.'/restore-*', GLOB_ONLYDIR) as $path) {
        if ($path !== $pending && ! is_link($path) && BackupPaths::validId(substr(basename($path), 8))) {
            BackupPaths::remove($path);
        }
    }
    $lastSuccess = is_file($state.'/last-success.json') ? BackupPaths::readJson($state.'/last-success.json')['id'] ?? null : null;
    foreach (glob($state.'/attempts/*.json') as $path) {
        $data = BackupPaths::readJson($path, 8192);
        $id = $data['id'] ?? '';
        if (is_string($id) && BackupPaths::validId($id) && $id !== $lastSuccess
            && ($data['status'] ?? '') !== 'running' && filemtime($path) < time() - 90 * 86400) {
            unlink($path);
        }
    }
    flock($runLock, LOCK_UN);
    fclose($runLock);
    flock($lock, LOCK_UN);
    fclose($lock);
} catch (Throwable) {
    fwrite(STDERR, "Backup finalization failed.\n");
    exit(1);
}
