<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Policies\AttachmentPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\DatabaseNotificationPolicy;
use App\Policies\ExpensePolicy;
use App\Security\AccountSessions;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PoliciesTest extends TestCase
{
    use DatabaseMigrations;

    /** @return array<string, array{Role, bool, bool}> */
    public static function roles(): array
    {
        return [
            'administrator' => [Role::Administrator, true, true],
            'editor' => [Role::Editor, true, false],
            'viewer' => [Role::Viewer, false, false],
        ];
    }

    /** @return array<string, array{Role}> */
    public static function allRoles(): array
    {
        return [
            'administrator' => [Role::Administrator],
            'editor' => [Role::Editor],
            'viewer' => [Role::Viewer],
        ];
    }

    #[DataProvider('roles')]
    public function test_expense_and_attachment_role_matrix(Role $role, bool $canWriteOwn, bool $canWriteOthers): void
    {
        $actor = $this->authorizedUser($role);
        $other = User::factory()->create();
        $own = Expense::factory()->for($actor, 'creator')->create();
        $foreign = Expense::factory()->for($other, 'creator')->create();
        $policy = app(ExpensePolicy::class);
        $files = app(AttachmentPolicy::class);

        $this->assertTrue($policy->viewAny($actor));
        $this->assertTrue($policy->view($actor, $own));
        $this->assertTrue($policy->view($actor, $foreign));
        $this->assertSame($canWriteOwn, $policy->create($actor));
        $this->assertSame($canWriteOthers, $policy->deleteAny($actor));
        $this->assertSame($canWriteOwn, Gate::forUser($actor)->allows('create', Expense::class));

        foreach ([[$own, $canWriteOwn], [$foreign, $canWriteOthers]] as [$expense, $canWrite]) {
            $attachment = Attachment::factory()->for($expense)->for($other, 'uploader')->create();

            $this->assertSame($canWrite, $policy->update($actor, $expense));
            $this->assertSame($canWrite, $policy->delete($actor, $expense));
            $this->assertTrue($files->view($actor, $attachment));
            $this->assertSame($canWrite, $files->create($actor, $expense));
            $this->assertSame($canWrite, $files->delete($actor, $attachment));
            $this->assertFalse($files->update($actor, $attachment));
            $this->assertFalse($files->restore($actor, $attachment));
            $this->assertFalse($files->forceDelete($actor, $attachment));
            $this->assertFalse($policy->restore($actor, $expense));
            $this->assertFalse($policy->forceDelete($actor, $expense));
        }

        $this->assertFalse($files->create($actor));
        $this->assertFalse($files->deleteAny($actor));
        $this->assertFalse($files->restoreAny($actor));
        $this->assertFalse($files->forceDeleteAny($actor));
        $this->assertFalse($policy->restoreAny($actor));
        $this->assertFalse($policy->forceDeleteAny($actor));
    }

    #[DataProvider('allRoles')]
    public function test_deleted_expenses_and_their_attachments_are_denied_to_every_role(Role $role): void
    {
        $actor = $this->authorizedUser($role);
        $expense = Expense::factory()->for($actor, 'creator')->create();
        $attachment = Attachment::factory()->for($expense)->create();
        $files = app(AttachmentPolicy::class);
        $this->assertTrue($files->view($actor, $attachment));
        $attachment->delete();
        $expense->delete();

        $policy = app(ExpensePolicy::class);
        $this->assertFalse($policy->view($actor, $expense));
        $this->assertFalse($policy->update($actor, $expense));
        $this->assertFalse($policy->delete($actor, $expense));
        $this->assertFalse($policy->restore($actor, $expense));
        $this->assertFalse($policy->forceDelete($actor, $expense));
        $this->assertFalse($files->view($actor, $attachment));
        $this->assertFalse($files->create($actor, $expense));
        $this->assertFalse($files->delete($actor, $attachment));
    }

    #[DataProvider('allRoles')]
    public function test_category_management_and_unused_category_deletion_are_administrator_only(Role $role): void
    {
        $actor = $this->authorizedUser($role);
        $category = Category::factory()->create();
        $policy = app(CategoryPolicy::class);
        $allowed = $role === Role::Administrator;

        $this->assertSame($allowed, $policy->viewAny($actor));
        $this->assertSame($allowed, $policy->view($actor, $category));
        $this->assertSame($allowed, $policy->create($actor));
        $this->assertSame($allowed, $policy->update($actor, $category));
        $this->assertSame($allowed, $policy->disable($actor, $category));
        $this->assertSame($allowed, Gate::forUser($actor)->allows('create', Category::class));
        $category->forceFill(['is_active' => false])->save();
        $this->assertSame($allowed, $policy->view($actor, $category));
        $this->assertSame($allowed, $policy->update($actor, $category));
        $this->assertSame($allowed, $policy->delete($actor, $category));
        Expense::factory()->create(['category_id' => $category->id]);
        $this->assertFalse($policy->delete($actor, $category));
        $this->assertFalse($policy->deleteAny($actor));
        $this->assertFalse($policy->restore($actor, $category));
        $this->assertFalse($policy->restoreAny($actor));
        $this->assertFalse($policy->forceDelete($actor, $category));
        $this->assertFalse($policy->forceDeleteAny($actor));
    }

    #[DataProvider('allRoles')]
    public function test_notifications_are_owner_only_even_for_administrators(Role $role): void
    {
        $actor = $this->authorizedUser($role);
        $other = User::factory()->create();
        $own = $this->notificationFor($actor);
        $foreign = $this->notificationFor($other);
        $policy = app(DatabaseNotificationPolicy::class);

        $this->assertTrue($policy->viewAny($actor));
        $this->assertTrue($policy->markAllAsRead($actor));
        foreach ([$own, $foreign] as $notification) {
            $owned = $notification->notifiable_id === $actor->id;
            $this->assertSame($owned, $policy->view($actor, $notification));
            $this->assertSame($owned, $policy->update($actor, $notification));
            $this->assertSame($owned, $policy->markAsRead($actor, $notification));
            $this->assertSame($owned, $policy->markAsUnread($actor, $notification));
            $this->assertSame($owned, Gate::forUser($actor)->allows('view', $notification));
            $this->assertFalse($policy->delete($actor, $notification));
            $this->assertFalse($policy->restore($actor, $notification));
            $this->assertFalse($policy->forceDelete($actor, $notification));
        }
        $own->forceFill(['notifiable_type' => Category::class])->save();
        $this->assertFalse($policy->view($actor, $own));
        $this->assertFalse($policy->create($actor));
        $this->assertFalse($policy->deleteAny($actor));
        $this->assertFalse($policy->restoreAny($actor));
        $this->assertFalse($policy->forceDeleteAny($actor));
    }

    /** @return array<string, array{string}> */
    public static function deniedSessions(): array
    {
        return [
            'enrollment-only' => ['enrollment-only'],
            'missing-enrollment' => ['missing-enrollment'],
            'disabled-user' => ['disabled-user'],
            'revoked-session' => ['revoked-session'],
            'different-authenticated-user' => ['different-authenticated-user'],
        ];
    }

    #[DataProvider('deniedSessions')]
    public function test_every_sensitive_policy_fails_closed_without_current_active_mfa_session(string $condition): void
    {
        $actor = $this->authorizedUser(Role::Administrator);
        $expense = Expense::factory()->for($actor, 'creator')->create();
        $attachment = Attachment::factory()->for($expense)->create();
        $category = Category::factory()->create();
        $notification = $this->notificationFor($actor);

        match ($condition) {
            'enrollment-only' => app(AccountSessions::class)->establish($actor, false),
            'missing-enrollment' => $actor->forceFill(['app_authentication_secret' => null])->save(),
            'disabled-user' => $actor->forceFill(['is_active' => false])->save(),
            'revoked-session' => $actor->forceFill(['auth_version' => $actor->auth_version + 1])->save(),
            'different-authenticated-user' => $this->actingAs(User::factory()->create()),
        };

        $expenses = app(ExpensePolicy::class);
        $files = app(AttachmentPolicy::class);
        $categories = app(CategoryPolicy::class);
        $notifications = app(DatabaseNotificationPolicy::class);
        $this->assertFalse($expenses->viewAny($actor));
        $this->assertFalse($expenses->view($actor, $expense));
        $this->assertFalse($expenses->create($actor));
        $this->assertFalse($expenses->update($actor, $expense));
        $this->assertFalse($expenses->delete($actor, $expense));
        $this->assertFalse($expenses->deleteAny($actor));
        $this->assertFalse($files->viewAny($actor));
        $this->assertFalse($files->view($actor, $attachment));
        $this->assertFalse($files->create($actor, $expense));
        $this->assertFalse($files->delete($actor, $attachment));
        $this->assertFalse($categories->viewAny($actor));
        $this->assertFalse($categories->view($actor, $category));
        $this->assertFalse($categories->create($actor));
        $this->assertFalse($categories->update($actor, $category));
        $this->assertFalse($categories->disable($actor, $category));
        $this->assertFalse($notifications->viewAny($actor));
        $this->assertFalse($notifications->view($actor, $notification));
        $this->assertFalse($notifications->update($actor, $notification));
        $this->assertFalse($notifications->markAsRead($actor, $notification));
        $this->assertFalse($notifications->markAsUnread($actor, $notification));
        $this->assertFalse($notifications->markAllAsRead($actor));
    }

    public function test_guests_are_denied_by_gate_before_policy_execution(): void
    {
        $expense = Expense::factory()->create();
        $category = Category::factory()->create();
        $attachment = Attachment::factory()->for($expense)->create();
        $notification = $this->notificationFor(User::factory()->create());

        $this->assertFalse(Gate::forUser(null)->allows('viewAny', Expense::class));
        $this->assertFalse(Gate::forUser(null)->allows('view', $expense));
        $this->assertFalse(Gate::forUser(null)->allows('create', Expense::class));
        $this->assertFalse(Gate::forUser(null)->allows('viewAny', Category::class));
        $this->assertFalse(Gate::forUser(null)->allows('view', $attachment));
        $this->assertFalse(Gate::forUser(null)->allows('view', $notification));
    }

    private function authorizedUser(Role $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, true);

        return $user;
    }

    private function notificationFor(User $user): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'test.notification',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => [],
        ]);
    }
}
