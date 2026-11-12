<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountService;

class DisableAccount extends AccountCommand
{
    protected $signature = 'accounts:disable {user : ID ou adresse e-mail}';

    protected $description = 'Désactiver un compte et révoquer ses sessions';

    public function handle(AccountService $accounts): int
    {
        return $this->safely(function () use ($accounts): void {
            $accounts->disable($accounts->resolveUser((string) $this->argument('user')));
            $this->info('Compte désactivé ; sessions révoquées.');
        });
    }
}
