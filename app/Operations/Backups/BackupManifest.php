<?php

declare(strict_types=1);

namespace App\Operations\Backups;

use RuntimeException;

final class BackupManifest
{
    public static function schema(): string
    {
        $parts = [];
        foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $file) {
            $parts[] = basename($file).':'.hash_file('sha256', $file);
        }

        return hash('sha256', implode("\n", $parts));
    }

    public static function validate(array $manifest, array $limits): array
    {
        if (($manifest['format'] ?? null) !== 1 || ! is_string($manifest['id'] ?? null) || ! BackupPaths::validId($manifest['id'])
            || ($manifest['schema'] ?? null) !== self::schema() || ($manifest['account_reset'] ?? null) !== true || ! is_array($manifest['attachments'] ?? null)
            || ! array_is_list($manifest['attachments']) || count($manifest['attachments']) + 2 > $limits['entries']) {
            throw new RuntimeException('Unsupported backup manifest or schema.');
        }
        $files = ['database.sqlite' => $manifest['database'] ?? []];
        foreach ($manifest['attachments'] as $file) {
            if (! is_array($file) || ! is_string($file['path'] ?? null)
                || ! preg_match('~\Aattachments/objects/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp|pdf)\z~', $file['path'])
                || isset($files[$file['path']])) {
                throw new RuntimeException('Invalid or duplicate attachment in backup manifest.');
            }
            $files[$file['path']] = $file;
        }
        $total = 0;
        foreach ($files as $file) {
            if (! is_int($file['bytes'] ?? null) || $file['bytes'] < 1 || $file['bytes'] > $limits['expanded_bytes']
                || ! is_string($file['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $file['sha256'])) {
                throw new RuntimeException('Invalid backup file size or checksum.');
            }
            if ($total > $limits['expanded_bytes'] - $file['bytes']) {
                throw new RuntimeException('Expanded backup exceeds the configured limit.');
            }
            $total += $file['bytes'];
        }

        return $files;
    }
}
