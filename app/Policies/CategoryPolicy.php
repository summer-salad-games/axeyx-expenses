<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Category;
use App\Models\User;
use App\Security\AccountAccess;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return app(AccountAccess::class)->allows($user) && $user->role === Role::Administrator;
    }

    public function view(User $user, Category $category): bool
    {
        return $category->exists && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->view($user, $category);
    }

    public function disable(User $user, Category $category): bool
    {
        return $this->update($user, $category);
    }

    public function delete(User $user, Category $category): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Category $category): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Category $category): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
