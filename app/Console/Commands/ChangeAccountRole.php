<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountService;

class ChangeAccountRole extends AccountCommand
{
    protected $signature = 'accounts:role {user : ID ou adresse e-mail} {role : administrator, editor ou viewer}';

    protected $description = 'Modifier le rôle et révoquer les sessions du compte';

    public function handle(AccountService $accounts): int
    {
        return $this->safely(function () use ($accounts): void {
            $role = $this->selectedRole((string) $this->argument('role'));
            $accounts->changeRole($accounts->resolveUser((string) $this->argument('user')), $role);
            $this->info('Rôle modifié ; sessions révoquées.');
        });
    }
}
