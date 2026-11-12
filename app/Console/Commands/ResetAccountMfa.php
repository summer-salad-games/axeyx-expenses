<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountService;

class ResetAccountMfa extends AccountCommand
{
    protected $signature = 'accounts:mfa-reset {user : ID ou adresse e-mail}';

    protected $description = 'Réinitialiser TOTP/récupération après confirmation et révoquer les sessions';

    public function handle(AccountService $accounts): int
    {
        return $this->safely(function () use ($accounts): void {
            $user = $accounts->resolveUser((string) $this->argument('user'));
            if (! $this->confirm('Réinitialiser la configuration TOTP et les codes de récupération de ce compte ?', false)) {
                $this->info('Opération annulée.');

                return;
            }
            $accounts->resetMfa($user);
            $this->info('TOTP réinitialisé ; sessions révoquées. Une nouvelle configuration sera obligatoire.');
        });
    }
}
