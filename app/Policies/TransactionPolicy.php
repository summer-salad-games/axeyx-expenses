<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Security\AccountAccess;

class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return app(AccountAccess::class)->allows($user);
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $this->viewAny($user) && $transaction->exists && Transaction::query()->whereKey($transaction->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && in_array($user->role, [Role::Administrator, Role::Editor], true);
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return $this->view($user, $transaction) && (
            $user->role === Role::Administrator
            || ($user->role === Role::Editor && $transaction->created_by === $user->id)
        );
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $this->update($user, $transaction);
    }

    public function deleteAny(User $user): bool
    {
        // Editors require per-record authorization; no global bulk permission.
        return $this->viewAny($user) && $user->role === Role::Administrator;
    }

    public function restore(User $user, Transaction $transaction): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Transaction $transaction): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
