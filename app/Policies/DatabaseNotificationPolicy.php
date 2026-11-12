<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Security\AccountAccess;
use Illuminate\Notifications\DatabaseNotification;

class DatabaseNotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return app(AccountAccess::class)->allows($user);
    }

    public function view(User $user, DatabaseNotification $notification): bool
    {
        return $this->viewAny($user)
            && $notification->exists
            && $notification->notifiable_type === $user->getMorphClass()
            && (string) $notification->notifiable_id === (string) $user->id;
    }

    public function update(User $user, DatabaseNotification $notification): bool
    {
        return $this->view($user, $notification);
    }

    public function markAsRead(User $user, DatabaseNotification $notification): bool
    {
        return $this->update($user, $notification);
    }

    public function markAsUnread(User $user, DatabaseNotification $notification): bool
    {
        return $this->update($user, $notification);
    }

    public function markAllAsRead(User $user): bool
    {
        // The action must update the current user's own notifications relation.
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, DatabaseNotification $notification): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, DatabaseNotification $notification): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, DatabaseNotification $notification): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
