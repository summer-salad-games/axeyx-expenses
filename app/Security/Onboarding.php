<?php

namespace App\Security;

use App\Models\User;

final class Onboarding
{
    public function requiredRoute(User $user): ?string
    {
        if (! filled($user->getAppAuthenticationSecret())) {
            return 'filament.admin.auth.multi-factor-authentication.set-up-required';
        }
        if ($user->must_change_password) {
            return 'filament.admin.setup.password';
        }

        return $user->onboarding_completed_at === null ? 'filament.admin.setup.complete' : null;
    }

    public function nextUrl(User $user): string
    {
        $route = $this->requiredRoute($user);

        return $route ? route($route) : url('/');
    }
}
