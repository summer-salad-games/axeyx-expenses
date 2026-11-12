<?php

use App\Models\User;
use App\Security\AppAuthentication;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing') {
    exit(1);
}
require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'file']);
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$user = User::findOrFail($input['user_id']);
$provider = AppAuthentication::make()->recoverable()->codeWindow(1);
echo $provider->verifyRecoveryCode($input['code'], $user) ? '1' : '0';
