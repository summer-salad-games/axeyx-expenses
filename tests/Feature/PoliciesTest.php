<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Policies\AttachmentPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\TransactionPolicy;
use App\Security\AccountSessions;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Gate;
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
        $own = Transaction::factory()->for($actor, 'creator')->create();
        $foreign = Transaction::factory()->for($other, 'creator')->create();
        $policy = app(TransactionPolicy::class);
        $files = app(AttachmentPolicy::class);

        $this->assertTrue($policy->viewAny($actor));
        $this->assertTrue($policy->view($actor, $own));
        $this->assertTrue($policy->view($actor, $foreign));
        $this->assertSame($canWriteOwn, $policy->create($actor));
        $this->assertSame($canWriteOthers, $policy->deleteAny($actor));
        $this->assertSame($canWriteOwn, Gate::forUser($actor)->allows('create', Transaction::class));

        foreach ([[$own, $canWriteOwn], [$foreign, $canWriteOthers]] as [$transaction, $canWrite]) {
            $attachment = Attachment::factory()->for($transaction)->for($other, 'uploader')->create();

            $this->assertSame($canWrite, $policy->update($actor, $transaction));
            $this->assertSame($canWrite, $policy->delete($actor, $transaction));
            $this->assertTrue($files->view($actor, $attachment));
            $this->assertSame($canWrite, $files->create($actor, $transaction));
            $this->assertSame($canWrite, $files->delete($actor, $attachment));
            $this->assertFalse($files->update($actor, $attachment));
            $this->assertFalse($files->restore($actor, $attachment));
            $this->assertFalse($files->forceDelete($actor, $attachment));
            $this->assertFalse($policy->restore($actor, $transaction));
            $this->assertFalse($policy->forceDelete($actor, $transaction));
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
        $transaction = Transaction::factory()->for($actor, 'creator')->create();
        $attachment = Attachment::factory()->for($transaction)->create();
        $files = app(AttachmentPolicy::class);
        $this->assertTrue($files->view($actor, $attachment));
        $attachment->delete();
        $transaction->delete();

        $policy = app(TransactionPolicy::class);
        $this->assertFalse($policy->view($actor, $transaction));
        $this->assertFalse($policy->update($actor, $transaction));
        $this->assertFalse($policy->delete($actor, $transaction));
        $this->assertFalse($policy->restore($actor, $transaction));
        $this->assertFalse($policy->forceDelete($actor, $transaction));
        $this->assertFalse($files->view($actor, $attachment));
        $this->assertFalse($files->create($actor, $transaction));
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
        Transaction::factory()->create(['category_id' => $category->id]);
        $this->assertFalse($policy->delete($actor, $category));
        $this->assertFalse($policy->deleteAny($actor));
        $this->assertFalse($policy->restore($actor, $category));
        $this->assertFalse($policy->restoreAny($actor));
        $this->assertFalse($policy->forceDelete($actor, $category));
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
        $transaction = Transaction::factory()->for($actor, 'creator')->create();
        $attachment = Attachment::factory()->for($transaction)->create();
        $category = Category::factory()->create();

        match ($condition) {
            'enrollment-only' => app(AccountSessions::class)->establish($actor, false),
            'missing-enrollment' => $actor->forceFill(['app_authentication_secret' => null])->save(),
            'disabled-user' => $actor->forceFill(['is_active' => false])->save(),
            'revoked-session' => $actor->forceFill(['auth_version' => $actor->auth_version + 1])->save(),
            'different-authenticated-user' => $this->actingAs(User::factory()->create()),
        };

        $transactions = app(TransactionPolicy::class);
        $files = app(AttachmentPolicy::class);
        $categories = app(CategoryPolicy::class);
        $this->assertFalse($transactions->viewAny($actor));
        $this->assertFalse($transactions->view($actor, $transaction));
        $this->assertFalse($transactions->create($actor));
        $this->assertFalse($transactions->update($actor, $transaction));
        $this->assertFalse($transactions->delete($actor, $transaction));
        $this->assertFalse($transactions->deleteAny($actor));
        $this->assertFalse($files->viewAny($actor));
        $this->assertFalse($files->view($actor, $attachment));
        $this->assertFalse($files->create($actor, $transaction));
        $this->assertFalse($files->delete($actor, $attachment));
        $this->assertFalse($categories->viewAny($actor));
        $this->assertFalse($categories->view($actor, $category));
        $this->assertFalse($categories->create($actor));
        $this->assertFalse($categories->update($actor, $category));
        $this->assertFalse($categories->disable($actor, $category));
    }

    public function test_guests_are_denied_by_gate_before_policy_execution(): void
    {
        $transaction = Transaction::factory()->create();
        $category = Category::factory()->create();
        $attachment = Attachment::factory()->for($transaction)->create();

        $this->assertFalse(Gate::forUser(null)->allows('viewAny', Transaction::class));
        $this->assertFalse(Gate::forUser(null)->allows('view', $transaction));
        $this->assertFalse(Gate::forUser(null)->allows('create', Transaction::class));
        $this->assertFalse(Gate::forUser(null)->allows('viewAny', Category::class));
        $this->assertFalse(Gate::forUser(null)->allows('view', $attachment));
    }

    private function authorizedUser(Role $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, true);

        return $user;
    }
}
