<?php

namespace App\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

final class TestIsolation
{
    public static function prepareDatabase(string $base, string $environment): string
    {
        if (! in_array($environment, ['testing', 'e2e'], true)) {
            throw new RuntimeException('Unknown test environment.');
        }
        foreach ([$base.'/.data', $base.'/.data/'.$environment] as $directory) {
            if (is_link($directory) || (file_exists($directory) && realpath($directory) !== $directory)) {
                throw new RuntimeException('Test directories must not be symlinks.');
            }
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
        }
        $root = $base.'/.data/'.$environment;
        if (file_exists($root.'/config.php') || is_link($root.'/config.php')) {
            throw new RuntimeException('Refusing a cached test configuration; remove the disposable test config cache first.');
        }
        $database = $root.'/database.sqlite';
        if (is_link($database) || (file_exists($database) && realpath($database) !== $database)) {
            throw new RuntimeException('Test database must not be a symlink.');
        }
        if (! file_exists($database)) {
            $handle = fopen($database, 'x');
            if ($handle === false) {
                throw new RuntimeException('Cannot create isolated database.');
            }
            fclose($handle);
            chmod($database, 0600);
        }
        putenv('AXEYX_TEST_RUNNER='.$environment);
        $_ENV['AXEYX_TEST_RUNNER'] = $_SERVER['AXEYX_TEST_RUNNER'] = $environment;

        return $database;
    }

    public static function configure(Application $app): void
    {
        $runner = getenv('AXEYX_TEST_RUNNER');
        if ($runner && $app->environment() !== $runner) {
            throw new RuntimeException('Loaded environment does not match the test runner.');
        }
        if (! $app->environment('testing', 'e2e')) {
            return;
        }

        $root = $app->basePath('.data/'.$app->environment());
        $database = realpath($app['config']->get('database.connections.sqlite.database'));
        $expected = realpath($root.'/database.sqlite');

        if (! $expected || $expected !== $root.'/database.sqlite' || $database !== $expected || realpath($root) !== $root) {
            throw new RuntimeException('Test database must be the isolated project-local database.');
        }

        $host = parse_url($app['config']->get('app.url'), PHP_URL_HOST);
        if (! in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true)) {
            throw new RuntimeException('Tests require a loopback application URL.');
        }

        $app['config']->set([
            'filesystems.disks.local.root' => $root.'/private',
            'filesystems.disks.attachments.root' => $root.'/attachments',
            'filesystems.disks.tmp-for-tests' => ['driver' => 'local', 'root' => $root.'/private', 'visibility' => 'private', 'throw' => true],
            'cache.stores.file.path' => $root.'/cache',
            'cache.stores.file.lock_path' => $root.'/locks',
            'session.files' => $root.'/sessions',
            'view.compiled' => $root.'/views',
            'mail.default' => 'array',
            'broadcasting.default' => 'null',
        ]);
        foreach (['private', 'attachments', 'cache', 'locks', 'sessions', 'views'] as $directory) {
            $path = $root.'/'.$directory;
            if (is_link($path) || (file_exists($path) && realpath($path) !== $path)) {
                throw new RuntimeException('Test storage directories must not be symlinks.');
            }
            if (! is_dir($root.'/'.$directory)) {
                mkdir($root.'/'.$directory, 0700, true);
            }
        }
    }
}
