<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class AccountAccessRevoked implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly int $userId, public readonly int $authVersion) {}
}
