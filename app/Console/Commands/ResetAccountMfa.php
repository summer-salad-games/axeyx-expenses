<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountService;

class ResetAccountMfa extends AccountCommand
{
    protected $signature = 'accounts:mfa-reset {user : Account ID or email address}';

    protected $description = 'Reset TOTP and recovery codes after confirmation and revoke sessions';

    public function handle(AccountService $accounts): int
    {
        return $this->safely(function () use ($accounts): void {
            $user = $accounts->resolveUser((string) $this->argument('user'));
            if (! $this->confirm('Reset TOTP setup and recovery codes for this account?', false)) {
                $this->info('Operation cancelled.');

                return;
            }
            $accounts->resetMfa($user);
            $this->info('TOTP reset; sessions revoked. New MFA setup is required.');
        });
    }
}
