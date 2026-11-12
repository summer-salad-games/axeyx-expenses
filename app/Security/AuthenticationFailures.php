<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Support\Facades\Log;

final class AuthenticationFailures
{
    public function record(string $factor, ?User $user = null): void
    {
        $ip = request()->ip();
        Log::notice('authentication.failed', [
            'factor' => $factor,
            'user_id' => $user?->getKey(),
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
        ]);
    }
}
