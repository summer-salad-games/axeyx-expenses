<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Expense;
use App\Models\User;
use App\Security\AccountAccess;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return app(AccountAccess::class)->allows($user);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->viewAny($user) && $expense->exists && ! $expense->trashed();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && in_array($user->role, [Role::Administrator, Role::Editor], true);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->view($user, $expense) && (
            $user->role === Role::Administrator
            || ($user->role === Role::Editor && $expense->created_by === $user->id)
        );
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->update($user, $expense);
    }

    public function deleteAny(User $user): bool
    {
        // Editors require per-record authorization; no global bulk permission.
        return $this->viewAny($user) && $user->role === Role::Administrator;
    }

    public function restore(User $user, Expense $expense): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
