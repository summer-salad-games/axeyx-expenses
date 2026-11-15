<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountService;

class ChangeAccountPassword extends AccountCommand
{
    protected $signature = 'accounts:password {user : Account ID or email address}';

    protected $description = 'Set a temporary password using hidden prompts and revoke sessions';

    public function handle(AccountService $accounts): int
    {
        return $this->safely(function () use ($accounts): void {
            $user = $accounts->resolveUser((string) $this->argument('user'));
            [$password, $confirmation] = $this->passwordInput();
            $accounts->changePassword($user, $password, $confirmation);
            $this->info('Temporary password set; sessions revoked. The user must choose a new password at their next login.');
        });
    }
}
