<?php

use App\Support\TestIsolation;

require dirname(__DIR__).'/vendor/autoload.php';

$database = TestIsolation::prepareDatabase(dirname(__DIR__), 'testing');
putenv('DB_DATABASE='.$database);
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;
