<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountService;

class CreateAccount extends AccountCommand
{
    protected $signature = 'accounts:create';

    protected $description = 'Create an account using hidden password prompts';

    public function handle(AccountService $accounts): int
    {
        return $this->safely(function () use ($accounts): void {
            $name = (string) $this->ask('Name');
            $email = (string) $this->ask('Email address');
            $role = $this->selectedRole((string) $this->choice('Role', ['administrator', 'editor', 'viewer'], 2));
            [$password, $confirmation] = $this->passwordInput();
            $user = $accounts->create($name, $email, $password, $confirmation, $role);
            $this->info("Account created (ID {$user->getKey()}). TOTP setup and a new password are required at first login.");
        });
    }
}
