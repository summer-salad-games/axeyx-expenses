<?php

use App\Operations\Backups\BackupPaths;
use App\Support\TestIsolation;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/Support/BackupSyncFault.php';

$database = TestIsolation::prepareDatabase(dirname(__DIR__), 'testing');
putenv('DB_DATABASE='.$database);
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

$environment = BackupPaths::directory(dirname($database).'/environment');
$temporary = $environment.'/.env-'.bin2hex(random_bytes(8));
BackupPaths::write($temporary, '');
BackupPaths::rename($temporary, $environment.'/.env');
