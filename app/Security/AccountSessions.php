<?php

namespace App\Security;

use App\Events\AccountAccessRevoked;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountSessions
{
    public function establish(User $user, bool $mfaComplete): void
    {
        session()->put('account_security', [
            'user_id' => $user->getKey(),
            'version' => $user->auth_version,
            'mfa_complete' => $mfaComplete,
        ]);
    }

    public function valid(User $user): bool
    {
        return $user->is_active
            && session('account_security.user_id') === $user->getKey()
            && session('account_security.version') === $user->auth_version;
    }

    public function revoke(User $user): void
    {
        DB::transaction(function () use ($user): void {
            User::query()->whereKey($user->getKey())->update(array_merge($user->getDirty(), [
                'auth_version' => DB::raw('auth_version + 1'),
                'remember_token' => Str::random(60),
            ]));
            $user->refresh();
            event(new AccountAccessRevoked($user->getKey(), $user->auth_version));
        });
    }
}
