<?php

namespace Tests\Support;

use App\Operations\Backups\BackupPaths;
use App\Operations\Backups\BackupTransport;
use Closure;
use RuntimeException;

final class LocalBackupTransport implements BackupTransport
{
    public ?Closure $onPublish = null;

    public bool $fail = false;

    public function __construct(public string $root)
    {
        BackupPaths::directory($root);
    }

    public function publish(string $id, string $directory): void
    {
        ($this->onPublish ?? fn () => null)();
        if ($this->fail) {
            throw new RuntimeException('Fixture upload failure.');
        }
        $target = BackupPaths::directory($this->root.'/'.$id);
        foreach (['backup.tar.gz', 'manifest.json'] as $name) {
            copy($directory.'/'.$name, $target.'/'.$name);
        }
        BackupPaths::json($target.'/complete.json', ['archive' => BackupPaths::hash($target.'/backup.tar.gz')]);
    }

    public function attempts(): array
    {
        return array_map('basename', glob($this->root.'/*', GLOB_ONLYDIR));
    }

    public function download(string $id, string $directory): array
    {
        foreach (['backup.tar.gz', 'manifest.json'] as $name) {
            copy($this->root.'/'.$id.'/'.$name, $directory.'/'.$name);
        }
        $marker = BackupPaths::readJson($this->root.'/'.$id.'/complete.json');
        if (BackupPaths::hash($directory.'/backup.tar.gz') !== $marker['archive']) {
            throw new RuntimeException('Fixture download checksum mismatch.');
        }

        return BackupPaths::readJson($directory.'/manifest.json');
    }

    public function prune(string $keepId): void {}
}
