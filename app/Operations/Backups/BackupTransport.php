<?php

namespace App\Operations\Backups;

interface BackupTransport
{
    public function publish(string $id, string $directory): void;

    public function attempts(): array;

    public function download(string $id, string $directory): array;

    public function prune(string $keepId): void;
}
