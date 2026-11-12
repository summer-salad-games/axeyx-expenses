<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountService;

class ChangeAccountPassword extends AccountCommand
{
    protected $signature = 'accounts:password {user : ID ou adresse e-mail}';

    protected $description = 'Changer le mot de passe par saisie masquée et révoquer les sessions';

    public function handle(AccountService $accounts): int
    {
        return $this->safely(function () use ($accounts): void {
            $user = $accounts->resolveUser((string) $this->argument('user'));
            [$password, $confirmation] = $this->passwordInput();
            $accounts->changePassword($user, $password, $confirmation);
            $this->info('Mot de passe temporaire défini ; sessions révoquées. L’utilisateur devra choisir un nouveau mot de passe à sa prochaine connexion.');
        });
    }
}
