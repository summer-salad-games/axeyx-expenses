<?php

declare(strict_types=1);

namespace App\Operations\Backups;

use RuntimeException;

final class BackupAttempt
{
    public function __construct(private string $root) {}

    public function start(string $id): void
    {
        BackupPaths::directory($this->root.'/attempts');
        if (! BackupPaths::validId($id) || file_exists($this->path($id))) {
            throw new RuntimeException('Invalid or reused backup attempt.');
        }
        BackupPaths::json($this->path($id), ['id' => $id, 'started_at' => gmdate(DATE_ATOM), 'status' => 'running', 'stage' => 'bootstrap']);
    }

    public function stage(string $id, string $stage): void
    {
        if (! in_array($stage, ['cleanup', 'snapshot', 'sanitize', 'archive', 'upload', 'retention'], true)) {
            throw new RuntimeException('Invalid backup stage.');
        }
        $data = BackupPaths::readJson($this->path($id));
        $data['stage'] = $stage;
        BackupPaths::json($this->path($id), $data);
    }

    public function finish(string $id, bool $success): void
    {
        $data = BackupPaths::readJson($this->path($id));
        $data['status'] = $success ? 'succeeded' : 'failed';
        $data['finished_at'] = gmdate(DATE_ATOM);
        BackupPaths::json($this->path($id), $data);
        if ($success) {
            BackupPaths::json($this->root.'/last-success.json', ['id' => $id, 'finished_at' => $data['finished_at']]);
        }
    }

    private function path(string $id): string
    {
        if (! BackupPaths::validId($id)) {
            throw new RuntimeException('Invalid backup attempt ID.');
        }

        return $this->root.'/attempts/'.$id.'.json';
    }
}
