<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;

class ListAccounts extends AccountCommand
{
    protected $signature = 'accounts:list';

    protected $description = 'Lister les comptes sans afficher de secrets';

    public function handle(): int
    {
        return $this->safely(function (): void {
            $rows = User::query()
                ->select(['id', 'name', 'email', 'role', 'is_active'])
                ->selectRaw('CASE WHEN app_authentication_secret IS NULL THEN 0 ELSE 1 END AS mfa_enabled')
                ->orderBy('id')->get()->map(fn (User $user): array => [
                    $user->getKey(),
                    $user->name,
                    $user->email,
                    $user->role->value,
                    $user->is_active ? 'oui' : 'non',
                    $user->getAttribute('mfa_enabled') ? 'configuré' : 'à configurer',
                ])->all();
            $this->table(['ID', 'Nom', 'E-mail', 'Rôle', 'Actif', 'TOTP'], $rows);
        });
    }
}
