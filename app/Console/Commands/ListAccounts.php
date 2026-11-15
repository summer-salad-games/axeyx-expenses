<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;

class ListAccounts extends AccountCommand
{
    protected $signature = 'accounts:list';

    protected $description = 'List accounts without exposing secrets';

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
                    $user->is_active ? 'yes' : 'no',
                    $user->getAttribute('mfa_enabled') ? 'configured' : 'not configured',
                ])->all();
            $this->table(['ID', 'Name', 'Email', 'Role', 'Active', 'TOTP'], $rows);
        });
    }
}
