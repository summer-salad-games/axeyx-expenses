<?php

// Isolated fake remote for command/verification/retention tests; never uses the network.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Operations\Backups\BackupPaths;

$args = $argv;
array_shift($args);
$configIndex = array_search('--config', $args, true);
$config = BackupPaths::readJson($args[$configIndex + 1]);
$root = $config['root'];
if (! str_starts_with($root, dirname(__DIR__, 2).'/.data/testing/')) {
    exit(9);
}
$verb = $args[0];
file_put_contents($root.'/commands.log', json_encode($args)."\n", FILE_APPEND);
if (($config['fail'] ?? '') === $verb) {
    fwrite(STDERR, "Fixture private provider error must not leak.\n");
    exit(7);
}
$path = function (string $value) use ($root): string {
    if (str_starts_with($value, 'fixture:')) {
        $relative = substr($value, 8);
        if (! preg_match('~\A[a-zA-Z0-9._/-]+\z~', $relative) || str_contains($relative, '..')) {
            exit(8);
        }

        return $root.'/'.$relative;
    }
    if (! str_starts_with($value, dirname(__DIR__, 2).'/.data/testing/')) {
        exit(8);
    }

    return $value;
};
try {
    if ($verb === 'copy') {
        $source = $path($args[1]);
        $destination = BackupPaths::directory($path($args[2]));
        foreach (glob($source.'/*') as $file) {
            if (is_file($file)) {
                copy($file, $destination.'/'.basename($file));
            }
        }
    } elseif ($verb === 'copyto') {
        $source = $path($args[1]);
        $target = $path($args[2]);
        BackupPaths::directory(dirname($target));
        if (! copy($source, $target)) {
            exit(3);
        }
    } elseif ($verb === 'check') {
        if (! in_array('--download', $args, true) || ! in_array('--one-way', $args, true)) {
            exit(8);
        }
        foreach (glob($path($args[1]).'/*') as $source) {
            if (is_file($source) && BackupPaths::hash($source) !== BackupPaths::hash($path($args[2]).'/'.basename($source))) {
                exit(4);
            }
        }
    } elseif ($verb === 'cat') {
        if (! is_file($path($args[1]))) {
            exit(3);
        }
        echo file_get_contents($path($args[1]));
    } elseif ($verb === 'lsjson') {
        $entries = [];
        foreach (glob($path($args[1]).'/*') as $file) {
            $isDir = is_dir($file);
            if ($isDir !== in_array('--dirs-only', $args, true)) {
                continue;
            }
            $entries[] = ['Name' => basename($file), 'Size' => $isDir ? -1 : filesize($file)];
        }
        echo json_encode($entries);
    } elseif ($verb === 'purge') {
        if (! in_array('--drive-use-trash=false', $args, true) || ! BackupPaths::validId(basename($args[1]))) {
            exit(8);
        }
        BackupPaths::remove($path($args[1]));
    } else {
        exit(8);
    }
} catch (Throwable) {
    exit(6);
}
