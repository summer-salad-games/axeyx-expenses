<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

final class AccountAccess
{
    public function allows(User $user): bool
    {
        $fresh = $user->fresh();

        return $fresh?->must_change_password === false
            && $fresh->onboarding_completed_at !== null
            && $this->verified($user, $fresh);
    }

    public function hasVerifiedMfa(User $user): bool
    {
        return $this->verified($user, $user->fresh());
    }

    private function verified(User $user, ?User $fresh): bool
    {
        return $fresh !== null
            && $fresh->role === $user->role
            && Auth::id() === $fresh->getKey()
            && app(AccountSessions::class)->valid($fresh)
            && filled($fresh->getAppAuthenticationSecret())
            && session('account_security.mfa_complete') === true;
    }
}
