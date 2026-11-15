<?php

declare(strict_types=1);

namespace App\Operations\Backups;

use Phar;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;

final class BackupArchive
{
    public function pack(string $data, string $archive, array $manifest, array $limits): void
    {
        $files = BackupManifest::validate($manifest, $limits);
        BackupPaths::json($data.'/manifest.json', $manifest);
        $tar = new PharData(substr($archive, 0, -3));
        foreach (['manifest.json', ...array_keys($files)] as $relative) {
            $path = $data.'/'.$relative;
            if (is_link($path) || ! is_file($path) || realpath($path) !== $path) {
                throw new RuntimeException('Unsafe file in backup staging.');
            }
            $tar->addFile($path, $relative);
        }
        $tar->compress(Phar::GZ);
        unset($tar);
        chmod($archive, 0600);
        unlink(substr($archive, 0, -3));
        if (filesize($archive) > $limits['archive_bytes']) {
            throw new RuntimeException('Backup archive exceeds the configured limit.');
        }
    }

    /** Invoked in a bounded child process; copy verified entries rather than extracting blindly. */
    public function unpack(string $archive, string $destination, array $manifest, array $limits): void
    {
        $files = BackupManifest::validate($manifest, $limits);
        if (is_link($archive) || ! is_file($archive) || filesize($archive) > $limits['archive_bytes']) {
            throw new RuntimeException('Unsafe or oversized backup archive.');
        }
        BackupPaths::directory($destination);
        if (iterator_count(new \FilesystemIterator($destination)) !== 0) {
            throw new RuntimeException('Restore staging must be empty.');
        }
        // Bound gzip expansion before PharData can allocate or spill a decompressed tar.
        $budget = array_sum(array_column($files, 'bytes')) + count($files) * 2048 + 17_825_792;
        $gzip = gzopen($archive, 'rb');
        if (! $gzip) {
            throw new RuntimeException('Invalid compressed archive.');
        }
        try {
            $expanded = 0;
            while (! gzeof($gzip)) {
                $chunk = gzread($gzip, 1048576);
                if ($chunk === false || ($chunk === '' && ! gzeof($gzip))) {
                    throw new RuntimeException('Truncated compressed archive.');
                }
                $expanded += strlen($chunk);
                if ($expanded > $budget) {
                    throw new RuntimeException('Compressed archive exceeds its declared expansion budget.');
                }
            }
        } finally {
            gzclose($gzip);
        }
        $tar = new PharData($archive);
        $prefix = 'phar://'.$archive.'/';
        $found = [];
        $count = 0;
        foreach (new RecursiveIteratorIterator($tar, RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if (++$count > $limits['entries'] + 3 || $entry->isLink()) {
                throw new RuntimeException('Unsafe backup archive entries.');
            }
            $name = $entry->getPathname();
            if (! str_starts_with($name, $prefix)) {
                throw new RuntimeException('Invalid archive member path.');
            }
            $relative = substr($name, strlen($prefix));
            if ($entry->isDir()) {
                if (! in_array($relative, ['attachments', 'attachments/objects'], true)) {
                    throw new RuntimeException('Unexpected archive directory.');
                }

                continue;
            }
            if (isset($found[$relative]) || ($relative !== 'manifest.json' && ! isset($files[$relative]))) {
                throw new RuntimeException('Unexpected or duplicate archive file.');
            }
            $maximum = $relative === 'manifest.json' ? 16_777_216 : $files[$relative]['bytes'];
            if ($entry->getSize() < 1 || $entry->getSize() > $maximum) {
                throw new RuntimeException('Archive member size does not match the manifest.');
            }
            $path = $destination.'/'.$relative;
            BackupPaths::directory(dirname($path));
            $input = fopen($name, 'rb');
            $output = fopen($path, 'x');
            if (! $input || ! $output) {
                throw new RuntimeException('Cannot stage archive member.');
            }
            chmod($path, 0600);
            try {
                $written = stream_copy_to_stream($input, $output, $maximum + 1);
                if (! fflush($output) || ! fsync($output)) {
                    throw new RuntimeException('Cannot persist extracted backup file.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            if ($written !== $entry->getSize()) {
                throw new RuntimeException('Truncated or oversized archive member.');
            }
            if ($relative !== 'manifest.json' && BackupPaths::hash($path) !== array_intersect_key($files[$relative], ['bytes' => 1, 'sha256' => 1])) {
                throw new RuntimeException('Archive member checksum mismatch.');
            }
            $found[$relative] = true;
        }
        if (count($found) !== count($files) + 1 || ! isset($found['manifest.json'])
            || BackupPaths::readJson($destination.'/manifest.json') !== $manifest) {
            throw new RuntimeException('Backup archive is incomplete or its manifest differs.');
        }
        $database = new BackupDatabase;
        if ($database->metadata($destination.'/database.sqlite')['attachments'] !== $manifest['attachments']) {
            throw new RuntimeException('Database attachment references differ from the manifest.');
        }
    }
}
