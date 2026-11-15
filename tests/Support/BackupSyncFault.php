<?php

namespace Tests\Support {
    final class BackupSyncFault
    {
        public static bool $verifiedJournal = false;

        public static ?int $freeBytes = null;
    }
}

namespace App\Operations\Backups {
    use Tests\Support\BackupSyncFault;

    function disk_free_space(string $path): float|false
    {
        return BackupSyncFault::$freeBytes ?? \disk_free_space($path);
    }

    // Test-only fault injection, loaded before backup helpers; production uses PHP fsync.
    function fsync($handle): bool
    {
        $uri = stream_get_meta_data($handle)['uri'] ?? '';
        if (BackupSyncFault::$verifiedJournal && str_contains($uri, 'restore.json.')
            && str_contains(file_get_contents($uri), '"phase": "verified"')) {
            return false;
        }

        return \fsync($handle);
    }
}
