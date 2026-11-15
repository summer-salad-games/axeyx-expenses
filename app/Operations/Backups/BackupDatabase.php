<?php

declare(strict_types=1);

namespace App\Operations\Backups;

use Illuminate\Support\Facades\Hash;
use RuntimeException;
use SQLite3;

final class BackupDatabase
{
    public const SCHEMA_SHA256 = '2f0d629a13df37ded062f62cb72bcc3b5c6e0d9683a84d20a6887c82900ec6c7';

    public const TABLES = ['attachments', 'audit_logs', 'categories', 'migrations', 'transactions', 'users'];

    public function open(string $path, bool $readonly = true): SQLite3
    {
        if (is_link($path) || ($readonly && ! is_file($path))) {
            throw new RuntimeException('Unsafe or missing SQLite database.');
        }
        $database = new SQLite3($path, $readonly ? SQLITE3_OPEN_READONLY : SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $database->enableExceptions(true);
        $database->busyTimeout(5000);
        $database->exec('PRAGMA foreign_keys=ON');
        $database->exec('PRAGMA synchronous=FULL');
        $database->exec('PRAGMA trusted_schema=OFF');
        $database->exec('PRAGMA temp_store=MEMORY');

        return $database;
    }

    public function logicalBytes(string $path): int
    {
        $database = $this->open($path);
        try {
            // Main-file size alone misses committed pages that still live in the WAL.
            return $database->querySingle('PRAGMA page_count') * $database->querySingle('PRAGMA page_size');
        } finally {
            $database->close();
        }
    }

    public function snapshot(string $source, string $destination): void
    {
        $input = $this->open($source);
        $output = $this->open($destination, false);
        chmod($destination, 0600);
        try {
            if (! $input->backup($output)) {
                throw new RuntimeException('SQLite online backup failed.');
            }
            $output->exec('PRAGMA journal_mode=DELETE');
            $this->check($output);
        } finally {
            $output->close();
            $input->close();
        }
    }

    public function fingerprint(SQLite3 $database): string
    {
        $rows = $database->query("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name");
        $schema = [];
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $row['sql'] = preg_replace('/\\s+/', ' ', trim($row['sql'] ?? ''));
            $schema[] = $row;
        }

        return hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR));
    }

    public function check(SQLite3 $database): void
    {
        if ($database->querySingle('PRAGMA integrity_check') !== 'ok' || $database->query('PRAGMA foreign_key_check')->fetchArray() !== false) {
            throw new RuntimeException('SQLite integrity or foreign-key verification failed.');
        }
        $tables = [];
        $rows = $database->query("SELECT type, name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name");
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            if (in_array($row['type'], ['trigger', 'view'], true)) {
                throw new RuntimeException('Unexpected executable database schema.');
            }
            if ($row['type'] === 'table') {
                $tables[] = $row['name'];
            }
        }
        if ($tables !== self::TABLES || $this->fingerprint($database) !== self::SCHEMA_SHA256) {
            throw new RuntimeException('Backup database schema is not supported.');
        }
    }

    public function sanitize(string $path): void
    {
        $database = $this->open($path, false);
        try {
            $this->check($database);
            // Only the snapshot is modified. Each discarded random password has its own valid hash.
            $ids = $database->query('SELECT id FROM users');
            $update = $database->prepare('UPDATE users SET password=:password, app_authentication_secret=NULL,
                app_authentication_recovery_codes=NULL, remember_token=NULL, auth_version=auth_version+1,
                must_change_password=1, onboarding_completed_at=NULL WHERE id=:id');
            $database->exec('BEGIN IMMEDIATE');
            while ($row = $ids->fetchArray(SQLITE3_ASSOC)) {
                $update->bindValue(':password', Hash::make(bin2hex(random_bytes(32))), SQLITE3_TEXT);
                $update->bindValue(':id', $row['id'], SQLITE3_INTEGER);
                $update->execute();
                $update->reset();
            }
            $database->exec('COMMIT');
            $ids->finalize();
            $update->close();
            // Explicit private target avoids a large VACUUM scratch database in system temp.
            BackupPaths::write($path.'.vacuum', '');
            $vacuum = $database->prepare('VACUUM INTO :destination');
            $vacuum->bindValue(':destination', $path.'.vacuum', SQLITE3_TEXT);
            $vacuum->execute();
            $vacuum->close();
            $this->check($database);
        } finally {
            $database->close();
        }
        BackupPaths::rename($path.'.vacuum', $path);
    }

    public function metadata(string $path): array
    {
        $database = $this->open($path);
        try {
            $this->check($database);
            $counts = [];
            foreach (self::TABLES as $table) {
                $counts[$table] = $database->querySingle('SELECT COUNT(*) FROM '.$table);
            }
            $attachments = [];
            $rows = $database->query('SELECT path, size_bytes, sha256 FROM attachments ORDER BY path');
            while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                $attachments[] = ['path' => 'attachments/'.$row['path'], 'bytes' => $row['size_bytes'], 'sha256' => $row['sha256']];
            }

            return ['counts' => $counts, 'attachments' => $attachments];
        } finally {
            $database->close();
        }
    }
}
