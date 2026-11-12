<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Expense;
use App\Models\User;
use App\Security\AccountAccess;

class AttachmentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(AccountAccess::class)->allows($user);
    }

    public function view(User $user, Attachment $attachment): bool
    {
        // Query the parent instead of trusting a possibly stale loaded relation.
        $expense = $attachment->expense()->first();

        return $attachment->exists && $expense !== null && app(ExpensePolicy::class)->view($user, $expense);
    }

    public function create(User $user, ?Expense $expense = null): bool
    {
        return $expense !== null && app(ExpensePolicy::class)->update($user, $expense);
    }

    public function update(User $user, Attachment $attachment): bool
    {
        // Stored bytes and metadata have no editing workflow; add/remove only.
        return false;
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        $expense = $attachment->expense()->first();

        return $attachment->exists && $expense !== null && app(ExpensePolicy::class)->update($user, $expense);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Attachment $attachment): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Attachment $attachment): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
