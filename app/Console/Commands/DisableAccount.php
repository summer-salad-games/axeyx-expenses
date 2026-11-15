<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountService;

class DisableAccount extends AccountCommand
{
    protected $signature = 'accounts:disable {user : Account ID or email address}';

    protected $description = 'Disable an account and revoke its sessions';

    public function handle(AccountService $accounts): int
    {
        return $this->safely(function () use ($accounts): void {
            $accounts->disable($accounts->resolveUser((string) $this->argument('user')));
            $this->info('Account disabled; sessions revoked.');
        });
    }
}
