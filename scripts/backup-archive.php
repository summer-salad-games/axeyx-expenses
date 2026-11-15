<?php

// Bounded archive parser. No Laravel boot or credentials are needed.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Operations\Backups\BackupArchive;
use App\Operations\Backups\BackupPaths;

if (PHP_SAPI !== 'cli' || count($argv) !== 6) {
    exit(2);
}
try {
    [$script, $operation, $data, $archive, $manifestPath, $limitsJson] = $argv;
    $manifest = BackupPaths::readJson($manifestPath);
    $limits = json_decode($limitsJson, true, flags: JSON_THROW_ON_ERROR);
    $service = new BackupArchive;
    if ($operation === 'pack') {
        $service->pack($data, $archive, $manifest, $limits);
    } elseif ($operation === 'unpack') {
        $service->unpack($archive, $data, $manifest, $limits);
    } else {
        exit(2);
    }
} catch (Throwable) {
    fwrite(STDERR, "Archive validation or staging failed.\n");
    exit(1);
}
