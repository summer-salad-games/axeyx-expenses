<?php

use App\Enums\Role;
use App\Models\User;
use App\Security\AppAuthentication;
use Database\Seeders\DefaultCategorySeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

abort_unless(app()->environment('e2e'), 403);
Cache::store('file')->flush();
app(DefaultCategorySeeder::class)->run();
$fixtures = [];
$provider = AppAuthentication::make()->recoverable();
foreach (['desktop', 'mobile'] as $project) {
    foreach (['login', 'setup', 'mfa_reset', 'm3_admin', 'm3_editor', 'm3_viewer'] as $flow) {
        $key = $project.'_'.$flow;
        $password = Str::password(32);
        $complete = ! in_array($flow, ['setup', 'mfa_reset'], true);
        $secret = $complete ? $provider->generateSecret() : null;
        $codes = $complete ? $provider->generateRecoveryCodes() : [];
        $user = new User;
        $user->forceFill([
            'name' => match ($flow) {
                'm3_admin' => 'Administrateur '.$project,
                'm3_editor' => 'Éditeur '.$project,
                'm3_viewer' => 'Lecteur '.$project,
                default => 'Compte de test',
            }, 'email' => $key.'@example.test',
            'password' => Hash::make($password), 'role' => match ($flow) {
                'm3_admin' => Role::Administrator,
                'm3_editor' => Role::Editor,
                default => Role::Viewer,
            },
            'must_change_password' => $flow === 'setup',
            'onboarding_completed_at' => $complete ? now() : null,
            'app_authentication_secret' => $secret,
            'app_authentication_recovery_codes' => array_map(fn ($code) => Hash::make($code), $codes),
        ])->save();
        $fixtures[$key] = ['email' => $user->email, 'password' => $password, 'secret' => $secret, 'recoveryCodes' => $codes];
    }
}
$path = base_path('.data/e2e/accounts.json');
file_put_contents($path, json_encode($fixtures, JSON_THROW_ON_ERROR));
chmod($path, 0600);
