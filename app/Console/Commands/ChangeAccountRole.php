<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountService;

class ChangeAccountRole extends AccountCommand
{
    protected $signature = 'accounts:role {user : Account ID or email address} {role : administrator, editor or viewer}';

    protected $description = 'Change an account role and revoke its sessions';

    public function handle(AccountService $accounts): int
    {
        return $this->safely(function () use ($accounts): void {
            $role = $this->selectedRole((string) $this->argument('role'));
            $accounts->changeRole($accounts->resolveUser((string) $this->argument('user')), $role);
            $this->info('Role changed; sessions revoked.');
        });
    }
}
