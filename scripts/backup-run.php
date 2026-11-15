<?php

// Deliberately independent of Laravel bootstrap: failures still leave a recorded failed attempt.
require dirname(__DIR__).'/app/Operations/Backups/BackupPaths.php';
require dirname(__DIR__).'/app/Operations/Backups/BackupAttempt.php';

use App\Operations\Backups\BackupAttempt;
use App\Operations\Backups\BackupPaths;

if (PHP_SAPI !== 'cli') {
    exit(2);
}
$process = null;
$lock = null;
$id = null;
$attempt = null;
$exit = 1;
try {
    $state = getenv('BACKUP_STATE_PATH') ?: throw new RuntimeException('BACKUP_STATE_PATH is required.');
    BackupPaths::directory($state);
    $lock = fopen($state.'/wrapper.lock', 'c');
    chmod($state.'/wrapper.lock', 0600);
    if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Backup wrapper is already running.');
    }
    $id = BackupPaths::id();
    $attempt = new BackupAttempt($state);
    $attempt->start($id);
    $interrupted = false;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function () use (&$interrupted): void {
                $interrupted = true;
            });
        }
    }
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/artisan', 'backups:run', '--attempt='.$id, '--no-interaction'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start backup command.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    do {
        // Discard arbitrary bootstrap exception text; it can include sensitive configuration.
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if ($interrupted) {
            proc_terminate($process);
            usleep(250000);
            proc_terminate($process, 9);
            break;
        }
        usleep(50000);
    } while ($status['running']);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closed = proc_close($process);
    $process = null;
    $exit = $interrupted ? 143 : ($status['exitcode'] >= 0 ? $status['exitcode'] : $closed);
    $data = BackupPaths::readJson($state.'/attempts/'.$id.'.json');
    if ($exit !== 0 || $data['status'] !== 'succeeded') {
        $attempt->finish($id, false);
        $exit = 1;
    }
    fwrite($exit === 0 ? STDOUT : STDERR, ($exit === 0 ? 'Backup verified: ' : 'Backup failed: ').$id."\n");
} catch (Throwable) {
    if ($id && $attempt) {
        try {
            $attempt->finish($id, false);
        } catch (Throwable) {
        }
    }
    fwrite(STDERR, "Backup wrapper failed; inspect the private attempt status.\n");
} finally {
    if (is_resource($process)) {
        proc_terminate($process, 9);
        proc_close($process);
    }
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
exit($exit);
