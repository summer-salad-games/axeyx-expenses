<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Security\AccountSessions;
use App\Services\AccountService;
use App\Services\CategoryService;
use App\Services\ExpenseService;
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

class ExpenseManagementTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
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
        return ['expense_date' => '2026-11-12', 'amount' => '12,501', 'category_id' => ($category ?? Category::factory()->create())->id, 'description' => 'Fournitures de bureau'];
    }

    #[DataProvider('roles')]
    public function test_every_role_can_read_shared_expenses_but_only_writers_can_create(string $role): void
    {
        $actor = $this->login($role);
        $expense = Expense::factory()->create();
        $this->get('/expenses')->assertOk();
        $this->get('/expenses/'.$expense->id)->assertOk();
        Livewire::test(ListExpenses::class)->assertCanSeeTableRecords([$expense]);
        if ($role === 'viewer') {
            $this->get('/expenses/create')->assertForbidden();
            $this->expectException(AuthorizationException::class);
        }
        $created = app(ExpenseService::class)->create($actor, $this->data());
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
        $expense = Expense::factory()->create(['created_by' => $own ? $actor->id : User::factory()->create()->id]);
        if (! $allowed) {
            $this->get('/expenses/'.$expense->id.'/edit')->assertForbidden();
            foreach (['update', 'delete'] as $action) {
                try {
                    $service = app(ExpenseService::class);
                    $action === 'update' ? $service->update($actor, $expense, $this->data($expense->category)) : $service->delete($actor, $expense);
                    $this->fail('Unauthorized write was accepted.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
                }
            }

            return;
        }
        $this->get('/expenses/'.$expense->id.'/edit')->assertOk();
        app(ExpenseService::class)->update($actor, $expense, $this->data($expense->category));
        $this->assertSame(12501, $expense->fresh()->amount_millimes);
        app(ExpenseService::class)->delete($actor, $expense);
        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    public function test_real_resource_pages_save_exact_amounts_and_ignore_protected_fields(): void
    {
        $actor = $this->login('editor');
        $data = $this->data();
        Livewire::test(CreateExpense::class)->fillForm($data)
            ->set('data.created_by', User::factory()->create()->id)->set('data.amount_millimes', 1)
            ->set('data.id', 99999)->set('data.deleted_at', now()->toISOString())->call('create')->assertHasNoFormErrors();
        $expense = Expense::query()->sole();
        $this->assertSame(12501, $expense->amount_millimes);
        $this->assertSame($actor->id, $expense->created_by);
        $this->assertNotSame(99999, $expense->id);
        Livewire::test(EditExpense::class, ['record' => $expense->id])->assertFormSet(['amount' => '12,501'])
            ->fillForm([...$data, 'amount' => '999999999.999'])->set('data.created_by', 99999)
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(999999999999, $expense->fresh()->amount_millimes);
        $this->assertSame($actor->id, $expense->fresh()->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.created', 'actor_id' => $actor->id, 'subject_type' => Expense::class, 'subject_id' => (string) $expense->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.updated']);
        $this->assertSame([], AuditLog::query()->first()->metadata);
    }

    public function test_validation_rejects_invalid_amounts_dates_and_descriptions(): void
    {
        $this->login();
        $data = $this->data();
        foreach ([['amount', '1e3'], ['amount', 12.5], ['amount', '0'], ['amount', '1.0001'], ['expense_date', '2026-02-30'], ['description', str_repeat('a', 5001)]] as [$key, $value]) {
            Livewire::test(CreateExpense::class)->fillForm([...$data, $key => $value])->call('create')->assertHasFormErrors([$key]);
        }
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_disabled_category_can_be_kept_but_cannot_be_newly_selected(): void
    {
        $actor = $this->login();
        $old = Category::factory()->create(['is_active' => false]);
        $other = Category::factory()->create(['is_active' => false]);
        $expense = Expense::factory()->create(['category_id' => $old->id]);
        Livewire::test(EditExpense::class, ['record' => $expense->id])->fillForm($this->data($old))->call('save')->assertHasNoFormErrors();
        foreach ([null, $expense] as $record) {
            try {
                $service = app(ExpenseService::class);
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
        $page = Livewire::test(CreateExpense::class)->fillForm($data);
        Category::query()->whereKey($data['category_id'])->update(['is_active' => false]);
        $page->call('create')->assertHasFormErrors(['category_id']);
        try {
            app(ExpenseService::class)->create($actor, $data);
            $this->fail('Stale category selection was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category_id', $exception->errors());
        }
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_permanent_delete_removes_expense_attachment_rows_and_delivery_routes(): void
    {
        $actor = $this->login();
        $expense = Expense::factory()->create();
        $attachment = Attachment::factory()->create(['expense_id' => $expense->id]);
        Livewire::test(ViewExpense::class, ['record' => $expense->id])->callAction('delete')->assertRedirect('/expenses');
        $this->get('/expenses/'.$expense->id)->assertNotFound();
        $this->get('/expenses/'.$expense->id.'/edit')->assertNotFound();
        Livewire::test(ListExpenses::class)->assertCanNotSeeTableRecords([$expense]);
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.deleted', 'actor_id' => $actor->id]);
    }

    public function test_search_filters_sort_and_pagination_include_shared_records(): void
    {
        $this->login();
        $category = Category::factory()->create(['name' => 'Matériel médical']);
        $creator = User::factory()->create(['name' => 'Docteur Martin']);
        $first = Expense::factory()->create(['expense_date' => '2026-11-01', 'category_id' => $category->id, 'created_by' => $creator->id, 'amount_millimes' => 1001, 'description' => 'Achat de référence']);
        $second = Expense::factory()->create(['expense_date' => '2026-11-30', 'amount_millimes' => 9000]);
        $deleted = Expense::factory()->create();
        $deleted->delete();
        Livewire::test(ListExpenses::class)->assertCanSeeTableRecords([$second, $first], inOrder: true)->assertCanNotSeeTableRecords([$deleted])
            ->searchTable('référence')->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        Livewire::test(ListExpenses::class)->searchTable('Matériel médical')->assertCanSeeTableRecords([$first]);
        Livewire::test(ListExpenses::class)->searchTable('Docteur Martin')->assertCanSeeTableRecords([$first]);
        Livewire::test(ListExpenses::class)->filterTable('category_id', $category->id)->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        Livewire::test(ListExpenses::class)->filterTable('created_by', $creator->id)->assertCanNotSeeTableRecords([$second]);
        Livewire::test(ListExpenses::class)->filterTable('period', ['from' => '2026-11-01', 'until' => '2026-11-01'])->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        Livewire::test(ListExpenses::class)->sortTable('amount_millimes', 'asc')->assertCanSeeTableRecords([$first, $second], inOrder: true);
        Expense::factory()->count(12)->create(['expense_date' => '2026-12-01']);
        $page = Livewire::test(ListExpenses::class);
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
            app(ExpenseService::class)->create($actor, $data);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('expenses', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_revoked_livewire_form_cannot_write(): void
    {
        $actor = $this->login('editor');
        $page = Livewire::test(CreateExpense::class)->fillForm($this->data());
        app(AccountService::class)->disable($actor);
        $page->call('create')->assertForbidden();
        $this->assertDatabaseCount('expenses', 0);
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
        $expense = Expense::factory()->create(['description' => 'Original']);
        $category = $expense->category;
        Livewire::test(ListExpenses::class)->callAction(TestAction::make('edit')->table($expense), data: $this->data($category));
        Livewire::test(ViewExpense::class, ['record' => $expense->id])->callAction('edit', data: $this->data($category));
        Livewire::test(ListCategories::class)->callAction(TestAction::make('edit')->table($category), data: ['name' => 'Forged']);
        Livewire::test(ListCategories::class)->callAction('create', data: ['name' => 'Forged create']);
        $this->assertSame('Original', $expense->fresh()->description);
        $this->assertSame($category->name, $category->fresh()->name);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_date_filters_validate_before_applying_and_reject_forged_applied_values(): void
    {
        $this->login();
        $expense = Expense::factory()->create(['expense_date' => '2026-11-12']);
        Livewire::test(ListExpenses::class)
            ->set('tableDeferredFilters.period', ['from' => '2026-11-30', 'until' => '2026-11-01'])
            ->call('applyTableFilters')->assertHasErrors(['tableDeferredFilters.period.until']);
        Livewire::test(ListExpenses::class)->filterTable('period', ['from' => "2026-11-12' OR 1=1 --"])
            ->assertCanNotSeeTableRecords([$expense]);
        Livewire::test(ListExpenses::class)->filterTable('period', ['from' => ['forged'], 'until' => ['forged']])
            ->assertCanNotSeeTableRecords([$expense]);
        Livewire::test(ListExpenses::class)
            ->set('tableDeferredFilters.period', ['from' => null, 'until' => '2026-11-12'])
            ->call('applyTableFilters')->assertHasNoErrors()->assertCanSeeTableRecords([$expense]);
    }

    public function test_sqlite_snapshot_retry_rechecks_a_concurrently_disabled_category(): void
    {
        $actor = $this->login();
        $data = $this->data();
        config(['database.connections.racer' => config('database.connections.sqlite')]);
        $raced = false;
        Event::listen('eloquent.creating: '.Expense::class, function () use ($data, &$raced): void {
            if (! $raced) {
                $raced = true;
                DB::connection('racer')->table('categories')->where('id', $data['category_id'])->update(['is_active' => false]);
            }
        });
        try {
            app(ExpenseService::class)->create($actor, $data);
            $this->fail('A concurrently disabled category was accepted.');
        } catch (ValidationException $exception) {
            $this->assertTrue($raced);
            $this->assertArrayHasKey('category_id', $exception->errors());
            $this->assertDatabaseCount('expenses', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_a_lower_configured_amount_limit_applies_to_resource_and_service_writes(): void
    {
        $actor = $this->login();
        $data = $this->data();
        config(['expenses.max_amount_millimes' => 1000]);
        Livewire::test(CreateExpense::class)->fillForm($data)->call('create')->assertHasFormErrors(['amount']);
        try {
            app(ExpenseService::class)->create($actor, $data);
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
        Expense::factory()->create(['category_id' => $used->id]);
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
        Expense::factory()->create(['category_id' => $category->id]);
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
                DB::connection('racer')->table('expenses')->insert([
                    'expense_date' => '2026-11-13', 'amount_millimes' => 1000,
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
        $this->assertDatabaseHas('expenses', ['category_id' => $category->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'category.deleted']);
    }

    public function test_create_another_clears_the_amount_and_creates_a_separate_next_expense(): void
    {
        $actor = $this->login('editor');
        $data = $this->data();
        $page = Livewire::test(CreateExpense::class)->fillForm($data)->call('createAnother')
            ->assertHasNoFormErrors()->assertFormSet(['amount' => '', 'category_id' => null, 'description' => null]);
        $this->assertDatabaseHas('expenses', ['amount_millimes' => 12501, 'created_by' => $actor->id]);
        $page->call('createAnother')->assertHasFormErrors(['amount', 'category_id']);
        $this->assertDatabaseCount('expenses', 1);
        $page->fillForm([...$data, 'amount' => '1,125'])->call('createAnother')
            ->assertHasNoFormErrors()->assertFormSet(['amount' => '']);
        $this->assertDatabaseCount('expenses', 2);
        $this->assertDatabaseHas('expenses', ['amount_millimes' => 1125, 'created_by' => $actor->id]);
    }
}
