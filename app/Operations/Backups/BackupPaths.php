<?php

declare(strict_types=1);

namespace App\Operations\Backups;

use RuntimeException;

final class BackupPaths
{
    public static function id(): string
    {
        return gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(8));
    }

    public static function validId(string $id): bool
    {
        return preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', $id) === 1;
    }

    public static function directory(string $path): string
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException('Backup paths must be absolute.');
        }
        $path = rtrim($path, '/');
        if ($path === '' || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new RuntimeException('Unsafe backup directory.');
        }
        $cursor = '';
        foreach (explode('/', ltrim($path, '/')) as $part) {
            $cursor .= '/'.$part;
            if (is_link($cursor) || (file_exists($cursor) && ! is_dir($cursor))) {
                throw new RuntimeException('Backup directories must not contain symlinks.');
            }
            if (! is_dir($cursor) && ! mkdir($cursor, 0700)) {
                throw new RuntimeException('Cannot create private backup directory.');
            }
        }
        if (realpath($path) !== $path || ! is_writable($path)) {
            throw new RuntimeException('Backup directory is unavailable.');
        }
        if (! chmod($path, 0700)) {
            throw new RuntimeException('Cannot protect backup directory.');
        }

        return $path;
    }

    public static function syncFile(string $path): void
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('Cannot synchronize recovery file.');
        }
        try {
            if (! fsync($handle)) {
                throw new RuntimeException('Cannot persist recovery file metadata.');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function syncDirectory(string $directory): void
    {
        $handle = fopen($directory, 'r');
        if (! $handle) {
            throw new RuntimeException('Cannot open directory for synchronization.');
        }
        try {
            if (! fsync($handle)) {
                throw new RuntimeException('Cannot persist filesystem directory changes.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** Exclusive private creation: permissions are restrictive before the first byte. */
    public static function copy(string $source, string $target): void
    {
        if (is_link($source) || ! is_file($source)) {
            throw new RuntimeException('Unsafe backup copy source.');
        }
        $input = fopen($source, 'rb');
        $output = fopen($target, 'x');
        if (! $input || ! $output) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Cannot create private recovery file.');
        }
        try {
            if (! chmod($target, 0600)) {
                throw new RuntimeException('Cannot protect recovery file.');
            }
            if (stream_copy_to_stream($input, $output) !== filesize($source) || ! fflush($output) || ! fsync($output)) {
                throw new RuntimeException('Cannot persist recovery file.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        self::syncDirectory(dirname($target));
    }

    public static function write(string $target, string $bytes): void
    {
        $output = fopen($target, 'x');
        if (! $output) {
            throw new RuntimeException('Cannot create private recovery metadata.');
        }
        try {
            if (! chmod($target, 0600)) {
                throw new RuntimeException('Cannot protect recovery metadata.');
            }
            if (fwrite($output, $bytes) !== strlen($bytes) || ! fflush($output) || ! fsync($output)) {
                throw new RuntimeException('Cannot persist recovery metadata.');
            }
        } finally {
            fclose($output);
        }
        self::syncDirectory(dirname($target));
    }

    public static function rename(string $from, string $to): void
    {
        if (is_link($from) || is_link($to) || ! rename($from, $to)) {
            throw new RuntimeException('Cannot atomically replace recovery data.');
        }
        self::syncDirectory(dirname($to));
        if (dirname($from) !== dirname($to)) {
            self::syncDirectory(dirname($from));
        }
    }

    public static function json(string $path, array $data): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = fopen($temporary, 'x');
        if (! $handle) {
            throw new RuntimeException('Cannot write backup metadata.');
        }
        try {
            if (! chmod($temporary, 0600)) {
                throw new RuntimeException('Cannot protect backup metadata.');
            }
            $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                throw new RuntimeException('Cannot persist backup metadata.');
            }
            if (! fsync($handle)) {
                throw new RuntimeException('Cannot synchronize backup metadata.');
            }
        } finally {
            fclose($handle);
        }
        if (is_link($path) || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot replace backup metadata.');
        }
        self::syncDirectory(dirname($path));
    }

    public static function readJson(string $path, int $maximum = 16_777_216): array
    {
        if (is_link($path) || ! is_file($path) || filesize($path) > $maximum) {
            throw new RuntimeException('Invalid backup metadata file.');
        }
        $data = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid backup metadata.');
        }

        return $data;
    }

    /** Remove only a caller-owned private staging tree; never follow links. */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (! unlink($path)) {
                throw new RuntimeException('Cannot remove temporary backup file.');
            }
        } elseif (is_dir($path)) {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
                self::remove($entry->getPathname());
            }
            if (! rmdir($path)) {
                throw new RuntimeException('Cannot remove temporary backup directory.');
            }
        }
    }

    public static function hash(string $path): array
    {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('Backup file is missing or unsafe.');
        }

        return ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    }
}
