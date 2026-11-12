<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Support\TestIsolation;
use Illuminate\Contracts\Console\Kernel;

$base = dirname(__DIR__);
$local = file_exists($base.'/.env') ? Dotenv\Dotenv::parse(file_get_contents($base.'/.env')) : [];
if (in_array(getenv('APP_ENV'), ['production', 'staging'], true) || in_array($local['APP_ENV'] ?? '', ['production', 'staging'], true)) {
    fwrite(STDERR, "E2E refuses production/staging environments.\n");
    exit(1);
}
$database = TestIsolation::prepareDatabase($base, 'e2e');
$directory = dirname($database);
$environment = [
    'APP_ENV' => 'e2e',
    'APP_DEBUG' => 'false',
    'ARGON_MEMORY' => '1024',
    'ARGON_TIME' => '1',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'APP_URL' => 'http://127.0.0.1:8011',
    'DB_DATABASE' => $directory.'/database.sqlite',
    'APP_CONFIG_CACHE' => $directory.'/config.php',
];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
if ($kernel->call('migrate:fresh', ['--force' => true]) !== 0) {
    fwrite(STDERR, $kernel->output());
    exit(1);
}
require $base.'/tests/Support/seed-e2e.php';
chdir($base.'/public');
pcntl_exec(PHP_BINARY, ['-S', '127.0.0.1:8011', '-t', $base.'/public', $base.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php']);
exit(1);
