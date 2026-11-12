<?php

use App\Enums\Role;
use App\Models\User;
use App\Security\AppAuthentication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

abort_unless(app()->environment('e2e'), 403);
Cache::store('file')->flush();
$fixtures = [];
$provider = AppAuthentication::make()->recoverable();
foreach (['desktop', 'mobile'] as $project) {
    foreach (['login', 'setup', 'mfa_reset'] as $flow) {
        $key = $project.'_'.$flow;
        $password = Str::password(32);
        $secret = $flow === 'login' ? $provider->generateSecret() : null;
        $codes = $flow === 'login' ? $provider->generateRecoveryCodes() : [];
        $user = new User;
        $user->forceFill([
            'name' => 'Compte de test', 'email' => $key.'@example.test',
            'password' => Hash::make($password), 'role' => Role::Viewer,
            'must_change_password' => $flow === 'setup',
            'onboarding_completed_at' => $flow === 'login' ? now() : null,
            'app_authentication_secret' => $secret,
            'app_authentication_recovery_codes' => array_map(fn ($code) => Hash::make($code), $codes),
        ])->save();
        $fixtures[$key] = ['email' => $user->email, 'password' => $password, 'secret' => $secret, 'recoveryCodes' => $codes];
    }
}
$path = base_path('.data/e2e/accounts.json');
file_put_contents($path, json_encode($fixtures, JSON_THROW_ON_ERROR));
chmod($path, 0600);
