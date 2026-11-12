<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountService;

class CreateAccount extends AccountCommand
{
    protected $signature = 'accounts:create';

    protected $description = 'Créer un compte avec un mot de passe saisi de manière masquée';

    public function handle(AccountService $accounts): int
    {
        return $this->safely(function () use ($accounts): void {
            $name = (string) $this->ask('Nom');
            $email = (string) $this->ask('Adresse e-mail');
            $role = $this->selectedRole((string) $this->choice('Rôle', ['administrator', 'editor', 'viewer'], 2));
            [$password, $confirmation] = $this->passwordInput();
            $user = $accounts->create($name, $email, $password, $confirmation, $role);
            $this->info("Compte créé (ID {$user->getKey()}). Configuration TOTP et choix d’un nouveau mot de passe obligatoires à la première connexion.");
        });
    }
}
