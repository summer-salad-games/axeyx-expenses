<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Transactions\Pages\CreateTransaction;
use App\Filament\Resources\Transactions\Pages\EditTransaction;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Transactions\Pages\ViewTransaction;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Security\AccountSessions;
use App\Services\AccountService;
use App\Services\CategoryService;
use App\Services\TransactionService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TransactionManagementTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_transaction_type_matches_its_category_and_can_be_changed_with_a_new_category(): void
    {
        $actor = $this->login();
        $expense = Category::factory()->create();
        $revenue = Category::factory()->create(['type' => 'revenue']);
        $service = app(TransactionService::class);
        foreach (['invalid', ['revenue'], null, 'revenue'] as $type) {
            try {
                $service->create($actor, [...$this->data($expense), 'type' => $type]);
                $this->fail('Invalid type/category accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('transactions', 0);
            }
        }
        $transaction = $service->create($actor, [...$this->data($revenue), 'type' => 'revenue']);
        $this->assertSame('revenue', $transaction->type->value);
        $this->assertSame(12501, $transaction->amount_millimes);
        foreach (['-1', '+1', '0'] as $amount) {
            try {
                $service->update($actor, $transaction, [...$this->data($revenue), 'type' => 'revenue', 'amount' => $amount]);
                $this->fail('Signed or zero magnitude accepted.');
            } catch (ValidationException) {
                $this->assertSame(12501, $transaction->fresh()->amount_millimes);
            }
        }
        $attachment = Attachment::factory()->create(['transaction_id' => $transaction->id]);
        $service->update($actor, $transaction, $this->data($expense));
        $this->assertSame($attachment->id, $transaction->attachments()->sole()->id);
        $this->assertSame('expense', $transaction->fresh()->type->value);
        $this->assertSame($actor->id, $transaction->fresh()->created_by);
        Livewire::test(CreateTransaction::class)->fillForm(['category_id' => $expense->id])
            ->set('data.type', 'revenue')->assertFormSet(['category_id' => null]);
        Livewire::test(ListTransactions::class)->filterTable('type', 'expense')->assertCanSeeTableRecords([$transaction]);
        Livewire::test(ListTransactions::class)->filterTable('type', 'revenue')->assertCanNotSeeTableRecords([$transaction]);
    }

    public function test_category_names_are_unique_per_type_and_the_shared_table_filters_by_type(): void
    {
        $actor = $this->login();
        $service = app(CategoryService::class);
        $expense = $service->create($actor, ['name' => 'Commun', 'type' => 'expense']);
        $revenue = $service->create($actor, ['name' => 'Commun', 'type' => 'revenue']);
        $this->assertSame('revenue', $revenue->type->value);
        Livewire::test(ListCategories::class)->assertCanSeeTableRecords([$expense, $revenue])
            ->filterTable('type', 'expense')->assertCanSeeTableRecords([$expense])->assertCanNotSeeTableRecords([$revenue])
            ->filterTable('type', 'revenue')->assertCanSeeTableRecords([$revenue])->assertCanNotSeeTableRecords([$expense]);
        try {
            $service->create($actor, ['name' => 'COMMUN', 'type' => 'revenue']);
            $this->fail('Duplicate category accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('categories', 2);
        }
        $this->expectException(ValidationException::class);
        $service->update($actor, $expense, ['name' => 'Commun', 'type' => 'revenue']);
    }

    public static function roles(): array
    {
        return [['administrator'], ['editor'], ['viewer']];
    }

    private function login(string $role = 'administrator'): User
    {
        $user = User::factory()->create(['role' => Role::from($role), 'app_authentication_secret' => 'TESTONLYSECRET']);
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, true);

        return $user;
    }

    private function data(?Category $category = null): array
    {
        return ['type' => 'expense', 'transaction_date' => '2026-11-12', 'amount' => '12,501', 'category_id' => ($category ?? Category::factory()->create())->id, 'description' => 'Fournitures de bureau'];
    }

    #[DataProvider('roles')]
    public function test_every_role_can_read_shared_expenses_but_only_writers_can_create(string $role): void
    {
        $actor = $this->login($role);
        $transaction = Transaction::factory()->create();
        $this->get('/transactions')->assertOk();
        $this->get('/transactions/'.$transaction->id)->assertOk();
        Livewire::test(ListTransactions::class)->assertCanSeeTableRecords([$transaction]);
        if ($role === 'viewer') {
            $this->get('/transactions/create')->assertForbidden();
            $this->expectException(AuthorizationException::class);
        }
        $created = app(TransactionService::class)->create($actor, $this->data());
        $this->assertSame(12501, $created->amount_millimes);
        $this->assertSame($actor->id, $created->created_by);
    }

    public static function editPermissions(): array
    {
        return [['administrator', true, true], ['administrator', false, true], ['editor', true, true], ['editor', false, false], ['viewer', true, false], ['viewer', false, false]];
    }

    #[DataProvider('editPermissions')]
    public function test_update_and_delete_permissions_are_enforced_on_fresh_records(string $role, bool $own, bool $allowed): void
    {
        $actor = $this->login($role);
        $transaction = Transaction::factory()->create(['created_by' => $own ? $actor->id : User::factory()->create()->id]);
        if (! $allowed) {
            $this->get('/transactions/'.$transaction->id.'/edit')->assertForbidden();
            foreach (['update', 'delete'] as $action) {
                try {
                    $service = app(TransactionService::class);
                    $action === 'update' ? $service->update($actor, $transaction, $this->data($transaction->category)) : $service->delete($actor, $transaction);
                    $this->fail('Unauthorized write was accepted.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
                }
            }

            return;
        }
        $this->get('/transactions/'.$transaction->id.'/edit')->assertOk();
        app(TransactionService::class)->update($actor, $transaction, $this->data($transaction->category));
        $this->assertSame(12501, $transaction->fresh()->amount_millimes);
        app(TransactionService::class)->delete($actor, $transaction);
        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }

    public function test_real_resource_pages_save_exact_amounts_and_ignore_protected_fields(): void
    {
        $actor = $this->login('editor');
        $data = $this->data();
        Livewire::test(CreateTransaction::class)->fillForm($data)
            ->set('data.created_by', User::factory()->create()->id)->set('data.amount_millimes', 1)
            ->set('data.id', 99999)->set('data.deleted_at', now()->toISOString())->call('create')->assertHasNoFormErrors();
        $transaction = Transaction::query()->sole();
        $this->assertSame(12501, $transaction->amount_millimes);
        $this->assertSame($actor->id, $transaction->created_by);
        $this->assertNotSame(99999, $transaction->id);
        Livewire::test(EditTransaction::class, ['record' => $transaction->id])->assertFormSet(['amount' => '12,501'])
            ->fillForm([...$data, 'amount' => '999999999.999'])->set('data.created_by', 99999)
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(999999999999, $transaction->fresh()->amount_millimes);
        $this->assertSame($actor->id, $transaction->fresh()->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction.created', 'actor_id' => $actor->id, 'subject_type' => Transaction::class, 'subject_id' => (string) $transaction->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction.updated']);
        $this->assertSame([], AuditLog::query()->first()->metadata);
    }

    public function test_validation_rejects_invalid_amounts_dates_and_descriptions(): void
    {
        $this->login();
        $data = $this->data();
        foreach ([['amount', '1e3'], ['amount', 12.5], ['amount', '0'], ['amount', '1.0001'], ['transaction_date', '2026-02-30'], ['description', str_repeat('a', 5001)]] as [$key, $value]) {
            Livewire::test(CreateTransaction::class)->fillForm([...$data, $key => $value])->call('create')->assertHasFormErrors([$key]);
        }
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_disabled_category_can_be_kept_but_cannot_be_newly_selected(): void
    {
        $actor = $this->login();
        $old = Category::factory()->create(['is_active' => false]);
        $other = Category::factory()->create(['is_active' => false]);
        $transaction = Transaction::factory()->create(['category_id' => $old->id]);
        Livewire::test(EditTransaction::class, ['record' => $transaction->id])->fillForm($this->data($old))->call('save')->assertHasNoFormErrors();
        foreach ([null, $transaction] as $record) {
            try {
                $service = app(TransactionService::class);
                $record ? $service->update($actor, $record, $this->data($other)) : $service->create($actor, $this->data($old));
                $this->fail('Disabled category was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('category_id', $exception->errors());
            }
        }
    }

    public function test_category_disabled_after_form_render_is_rechecked_at_save(): void
    {
        $actor = $this->login();
        $data = $this->data();
        $page = Livewire::test(CreateTransaction::class)->fillForm($data);
        Category::query()->whereKey($data['category_id'])->update(['is_active' => false]);
        $page->call('create')->assertHasFormErrors(['category_id']);
        try {
            app(TransactionService::class)->create($actor, $data);
            $this->fail('Stale category selection was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category_id', $exception->errors());
        }
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_permanent_delete_removes_expense_attachment_rows_and_delivery_routes(): void
    {
        $actor = $this->login();
        $transaction = Transaction::factory()->create();
        $attachment = Attachment::factory()->create(['transaction_id' => $transaction->id]);
        Livewire::test(ViewTransaction::class, ['record' => $transaction->id])->callAction('delete')->assertRedirect('/transactions');
        $this->get('/transactions/'.$transaction->id)->assertNotFound();
        $this->get('/transactions/'.$transaction->id.'/edit')->assertNotFound();
        Livewire::test(ListTransactions::class)->assertCanNotSeeTableRecords([$transaction]);
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction.deleted', 'actor_id' => $actor->id]);
    }

    public function test_search_filters_sort_and_pagination_include_shared_records(): void
    {
        $this->login();
        $category = Category::factory()->create(['name' => 'Matériel médical']);
        $creator = User::factory()->create(['name' => 'Docteur Martin']);
        $first = Transaction::factory()->create(['type' => 'expense', 'transaction_date' => '2026-11-01', 'category_id' => $category->id, 'created_by' => $creator->id, 'amount_millimes' => 1001, 'description' => 'Achat de référence']);
        $second = Transaction::factory()->create(['type' => 'expense', 'transaction_date' => '2026-11-30', 'amount_millimes' => 9000]);
        $deleted = Transaction::factory()->create();
        $deleted->delete();
        Livewire::test(ListTransactions::class)->assertCanSeeTableRecords([$second, $first], inOrder: true)->assertCanNotSeeTableRecords([$deleted])
            ->searchTable('référence')->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        Livewire::test(ListTransactions::class)->searchTable('Matériel médical')->assertCanSeeTableRecords([$first]);
        Livewire::test(ListTransactions::class)->searchTable('Docteur Martin')->assertCanSeeTableRecords([$first]);
        Livewire::test(ListTransactions::class)->filterTable('category_id', $category->id)->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        Livewire::test(ListTransactions::class)->filterTable('created_by', $creator->id)->assertCanNotSeeTableRecords([$second]);
        Livewire::test(ListTransactions::class)->filterTable('period', ['from' => '2026-11-01', 'until' => '2026-11-01'])->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        Livewire::test(ListTransactions::class)->sortTable('amount_millimes', 'asc')->assertCanSeeTableRecords([$second, $first], inOrder: true);
        Transaction::factory()->count(12)->create(['type' => 'expense', 'transaction_date' => '2026-12-01']);
        $page = Livewire::test(ListTransactions::class);
        $this->assertCount(10, $page->instance()->getTableRecords());
        $page->call('gotoPage', 2);
        $this->assertCount(4, $page->instance()->getTableRecords());
    }

    public function test_an_audit_failure_rolls_back_the_expense_write(): void
    {
        $actor = $this->login();
        $data = $this->data();
        Event::listen('eloquent.creating: '.AuditLog::class, fn () => throw new RuntimeException('Synthetic audit failure'));
        try {
            app(TransactionService::class)->create($actor, $data);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('transactions', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_revoked_livewire_form_cannot_write(): void
    {
        $actor = $this->login('editor');
        $page = Livewire::test(CreateTransaction::class)->fillForm($this->data());
        app(AccountService::class)->disable($actor);
        $page->call('create')->assertForbidden();
        $this->assertDatabaseCount('transactions', 0);
    }

    #[DataProvider('roles')]
    public function test_only_administrators_can_manage_categories(string $role): void
    {
        $this->login($role);
        if ($role !== 'administrator') {
            $this->get('/categories')->assertForbidden();
            Livewire::test(CreateCategory::class)->assertForbidden();

            return;
        }
        Livewire::test(CreateCategory::class)->fillForm(['name' => '  Équipement  ', 'is_active' => true])
            ->set('data.name_key', 'forged')->call('create')->assertHasNoFormErrors();
        $category = Category::query()->sole();
        $this->assertSame('Équipement', $category->name);
        $this->assertSame('équipement', $category->name_key);
        Livewire::test(EditCategory::class, ['record' => $category->id])->fillForm(['name' => 'Équipement médical', 'is_active' => false])
            ->call('save')->assertHasNoFormErrors();
        $this->assertFalse($category->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'category.disabled', 'subject_type' => Category::class]);
        Livewire::test(ListCategories::class)->assertCanSeeTableRecords([$category]);
    }

    public function test_category_uniqueness_uses_unicode_casefolding_and_normalization(): void
    {
        $actor = $this->login();
        $service = app(CategoryService::class);
        $service->create($actor, ['name' => 'Équipement']);
        foreach (["E\u{0301}QUIPEMENT", ' ÉQUIPEMENT '] as $duplicate) {
            try {
                $service->create($actor, ['name' => $duplicate]);
                $this->fail('Duplicate category was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('name', $exception->errors());
            }
        }
        Livewire::test(CreateCategory::class)->fillForm(['name' => "\u{00a0}  "])->call('create')->assertHasFormErrors(['name']);
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_navigation_actions_cannot_be_invoked_as_unaudited_mutations(): void
    {
        $this->login();
        $transaction = Transaction::factory()->create(['description' => 'Original']);
        $category = $transaction->category;
        Livewire::test(ListTransactions::class)->callAction(TestAction::make('edit')->table($transaction), data: $this->data($category));
        Livewire::test(ViewTransaction::class, ['record' => $transaction->id])->callAction('edit', data: $this->data($category));
        Livewire::test(ListCategories::class)->callAction(TestAction::make('edit')->table($category), data: ['name' => 'Forged']);
        Livewire::test(ListCategories::class)->callAction('create', data: ['name' => 'Forged create']);
        $this->assertSame('Original', $transaction->fresh()->description);
        $this->assertSame($category->name, $category->fresh()->name);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_date_filters_validate_before_applying_and_reject_forged_applied_values(): void
    {
        $this->login();
        $transaction = Transaction::factory()->create(['type' => 'expense', 'transaction_date' => '2026-11-12']);
        Livewire::test(ListTransactions::class)
            ->set('tableDeferredFilters.period', ['from' => '2026-11-30', 'until' => '2026-11-01'])
            ->call('applyTableFilters')->assertHasErrors(['tableDeferredFilters.period.until']);
        Livewire::test(ListTransactions::class)->filterTable('period', ['from' => "2026-11-12' OR 1=1 --"])
            ->assertCanNotSeeTableRecords([$transaction]);
        Livewire::test(ListTransactions::class)->filterTable('period', ['from' => ['forged'], 'until' => ['forged']])
            ->assertCanNotSeeTableRecords([$transaction]);
        Livewire::test(ListTransactions::class)
            ->set('tableDeferredFilters.period', ['from' => null, 'until' => '2026-11-12'])
            ->call('applyTableFilters')->assertHasNoErrors()->assertCanSeeTableRecords([$transaction]);
    }

    public function test_sqlite_snapshot_retry_rechecks_a_concurrently_disabled_category(): void
    {
        $actor = $this->login();
        $data = $this->data();
        config(['database.connections.racer' => config('database.connections.sqlite')]);
        $raced = false;
        Event::listen('eloquent.creating: '.Transaction::class, function () use ($data, &$raced): void {
            if (! $raced) {
                $raced = true;
                DB::connection('racer')->table('categories')->where('id', $data['category_id'])->update(['is_active' => false]);
            }
        });
        try {
            app(TransactionService::class)->create($actor, $data);
            $this->fail('A concurrently disabled category was accepted.');
        } catch (ValidationException $exception) {
            $this->assertTrue($raced);
            $this->assertArrayHasKey('category_id', $exception->errors());
            $this->assertDatabaseCount('transactions', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_a_lower_configured_amount_limit_applies_to_resource_and_service_writes(): void
    {
        $actor = $this->login();
        $data = $this->data();
        config(['transactions.max_amount_millimes' => 1000]);
        Livewire::test(CreateTransaction::class)->fillForm($data)->call('create')->assertHasFormErrors(['amount']);
        try {
            app(TransactionService::class)->create($actor, $data);
            $this->fail('Configured maximum was ignored.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
    }

    #[DataProvider('roles')]
    public function test_only_administrators_can_delete_unused_categories(string $role): void
    {
        $actor = $this->login($role);
        $category = Category::factory()->create();
        if ($role !== 'administrator') {
            $this->expectException(AuthorizationException::class);
        }
        app(CategoryService::class)->delete($actor, $category);
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'category.deleted', 'subject_id' => (string) $category->id, 'actor_id' => $actor->id]);
    }

    public function test_category_delete_action_only_exists_when_unused_and_uses_audited_service(): void
    {
        $actor = $this->login();
        $unused = Category::factory()->create();
        $used = Category::factory()->create();
        Transaction::factory()->create(['category_id' => $used->id]);
        Livewire::test(ListCategories::class)
            ->assertTableActionVisible('delete', $unused)->assertTableActionHidden('delete', $used)
            ->callAction(TestAction::make('delete')->table($unused))->assertHasNoActionErrors();
        $this->assertDatabaseMissing('categories', ['id' => $unused->id]);
        Livewire::test(EditCategory::class, ['record' => $used->id])->assertActionHidden('delete');
        app(CategoryService::class)->update($actor, $used, ['name' => $used->name, 'is_active' => false]);
        $this->assertFalse($used->fresh()->is_active);
        $other = Category::factory()->create();
        Livewire::test(EditCategory::class, ['record' => $other->id])->callAction('delete')->assertRedirect('/categories');
        $this->assertDatabaseMissing('categories', ['id' => $other->id]);
    }

    public function test_category_delete_rechecks_expense_references(): void
    {
        $actor = $this->login();
        $category = Category::factory()->create();
        Transaction::factory()->create(['category_id' => $category->id]);
        try {
            app(CategoryService::class)->delete($actor, $category);
            $this->fail('Referenced category deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_concurrent_expense_creation_prevents_category_deletion_after_snapshot_retry(): void
    {
        $actor = $this->login();
        $category = Category::factory()->create();
        config(['database.connections.racer' => config('database.connections.sqlite')]);
        $raced = false;
        Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $audit) use ($actor, $category, &$raced): void {
            if ($audit->action === 'category.deleted' && ! $raced) {
                $raced = true;
                DB::connection('racer')->table('transactions')->insert([
                    'type' => 'expense', 'transaction_date' => '2026-11-13', 'amount_millimes' => 1000,
                    'category_id' => $category->id, 'created_by' => $actor->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        try {
            app(CategoryService::class)->delete($actor, $category);
            $this->fail('Concurrently referenced category deleted.');
        } catch (ValidationException) {
            $this->assertTrue($raced);
        }
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('transactions', ['category_id' => $category->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'category.deleted']);
    }

    public function test_create_another_clears_the_amount_and_creates_a_separate_next_expense(): void
    {
        $actor = $this->login('editor');
        $data = $this->data();
        $page = Livewire::test(CreateTransaction::class)->fillForm($data)->call('createAnother')
            ->assertHasNoFormErrors()->assertFormSet(['amount' => '', 'category_id' => null, 'description' => null]);
        $this->assertDatabaseHas('transactions', ['amount_millimes' => 12501, 'created_by' => $actor->id]);
        $page->call('createAnother')->assertHasFormErrors(['amount', 'category_id']);
        $this->assertDatabaseCount('transactions', 1);
        $page->fillForm([...$data, 'amount' => '1,125'])->call('createAnother')
            ->assertHasNoFormErrors()->assertFormSet(['amount' => '']);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseHas('transactions', ['amount_millimes' => 1125, 'created_by' => $actor->id]);
    }
}
