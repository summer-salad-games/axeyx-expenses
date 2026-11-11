<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Support\TestIsolation;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_sqlite_safety_settings_survive_reconnection(): void
    {
        $database = DB::connection()->getDatabaseName();

        $this->assertNotSame(':memory:', $database);
        $this->assertStringStartsWith(base_path('.data/'), $database);

        foreach ([false, true] as $reconnect) {
            if ($reconnect) {
                DB::purge('sqlite');
            }

            $pdo = DB::connection()->getPdo();
            $this->assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
            $this->assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
            $this->assertSame(2, (int) $pdo->query('PRAGMA synchronous')->fetchColumn());
        }
    }

    public function test_isolation_refuses_a_database_outside_the_disposable_test_location(): void
    {
        $original = config('database.connections.sqlite.database');
        config(['database.connections.sqlite.database' => base_path('database/database.sqlite')]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test database must be the isolated project-local database.');

        try {
            TestIsolation::configure($this->app);
        } finally {
            config(['database.connections.sqlite.database' => $original]);
        }
    }

    public function test_isolation_refuses_a_non_loopback_application_url(): void
    {
        $original = config('app.url');
        config(['app.url' => 'https://expenses.axeyx.com']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tests require a loopback application URL.');

        try {
            TestIsolation::configure($this->app);
        } finally {
            config(['app.url' => $original]);
        }
    }

    public function test_isolation_refuses_a_database_symlink_even_when_its_target_is_project_local(): void
    {
        [$fixture, $root, $application] = $this->isolationFixture();
        file_put_contents($fixture.'/outside/database.sqlite', 'preserve fixture');
        unlink($root.'/database.sqlite');
        symlink($fixture.'/outside/database.sqlite', $root.'/database.sqlite');
        $this->expectException(RuntimeException::class);

        try {
            TestIsolation::configure($application);
        } finally {
            $this->assertSame('preserve fixture', file_get_contents($fixture.'/outside/database.sqlite'));
            File::deleteDirectory($fixture);
        }
    }

    /** @return array<string, array{string}> */
    public static function isolatedDirectories(): array
    {
        return array_combine(
            ['private', 'attachments', 'cache', 'locks', 'sessions', 'views'],
            array_map(fn (string $directory): array => [$directory], ['private', 'attachments', 'cache', 'locks', 'sessions', 'views']),
        );
    }

    #[DataProvider('isolatedDirectories')]
    public function test_isolation_refuses_symlinked_writable_directories(string $directory): void
    {
        [$fixture, $root, $application] = $this->isolationFixture();
        mkdir($fixture.'/outside/'.$directory, 0700);
        file_put_contents($fixture.'/outside/'.$directory.'/sentinel', 'preserve fixture');
        symlink($fixture.'/outside/'.$directory, $root.'/'.$directory);
        $this->expectException(RuntimeException::class);

        try {
            TestIsolation::configure($application);
        } finally {
            $this->assertSame('preserve fixture', file_get_contents($fixture.'/outside/'.$directory.'/sentinel'));
            File::deleteDirectory($fixture);
        }
    }

    public function test_isolation_refuses_cached_configuration_before_touching_the_database(): void
    {
        [$fixture, $root] = $this->isolationFixture();
        file_put_contents($root.'/database.sqlite', 'preserve fixture');
        file_put_contents($root.'/config.php', '<?php return [];');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing a cached test configuration');

        try {
            TestIsolation::prepareDatabase($fixture, 'testing');
        } finally {
            $this->assertSame('preserve fixture', file_get_contents($root.'/database.sqlite'));
            File::deleteDirectory($fixture);
        }
    }

    public function test_isolation_refuses_an_environment_that_differs_from_the_test_runner(): void
    {
        [$fixture, , $application] = $this->isolationFixture();
        $application->instance('env', 'production');
        $runner = getenv('AXEYX_TEST_RUNNER');
        putenv('AXEYX_TEST_RUNNER=testing');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Loaded environment does not match the test runner.');

        try {
            TestIsolation::configure($application);
        } finally {
            putenv($runner === false ? 'AXEYX_TEST_RUNNER' : 'AXEYX_TEST_RUNNER='.$runner);
            File::deleteDirectory($fixture);
        }
    }

    public function test_expense_date_remains_date_only_when_given_a_datetime(): void
    {
        $expense = Expense::factory()->create([
            'expense_date' => CarbonImmutable::parse('2026-11-11 12:30:00'),
        ]);

        $this->assertSame('2026-11-11', DB::table('expenses')->where('id', $expense->id)->value('expense_date'));
    }

    public function test_migrations_and_seeding_create_no_accounts_or_database_sessions_cache(): void
    {
        $this->seed();

        $this->assertDatabaseCount('users', 0);
        $this->assertFalse(Schema::hasTable('sessions'));
        $this->assertFalse(Schema::hasTable('cache'));
        $this->assertFalse(Schema::hasTable('cache_locks'));
        $this->assertFalse(Schema::hasTable('password_reset_tokens'));
    }

    public function test_database_rejects_unknown_roles_even_without_model_validation(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'name' => 'Compte de test',
            'email' => 'invalid-role@example.test',
            'password' => 'not-a-real-credential',
            'role' => 'owner',
        ]);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidAmounts(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'fractional-storage' => [12.125],
            'non-numeric' => ['invalid'],
            'above-ceiling' => [1000000000000],
            'missing' => [null],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_database_rejects_invalid_stored_money(mixed $amount): void
    {
        $expense = $this->expenseAttributes($amount);
        $this->expectException(QueryException::class);

        DB::table('expenses')->insert($expense);
    }

    public function test_sqlite_aggregates_millimes_as_exact_integers(): void
    {
        $expense = $this->expenseAttributes(12500);
        DB::table('expenses')->insert($expense);
        DB::table('expenses')->insert([...$expense, 'amount_millimes' => 1001]);

        $result = DB::table('expenses')->selectRaw('SUM(amount_millimes) AS total, COUNT(*) AS count')->first();

        $this->assertSame(13501, $result->total);
        $this->assertSame(2, $result->count);
    }

    public function test_database_rejects_expenses_with_missing_categories(): void
    {
        $expense = $this->expenseAttributes(12500);
        $this->expectException(QueryException::class);

        DB::table('expenses')->insert([...$expense, 'category_id' => 999999]);
    }

    public function test_categories_referenced_by_expenses_cannot_be_hard_deleted(): void
    {
        $expense = $this->expenseAttributes(12500);
        DB::table('expenses')->insert($expense);
        $this->expectException(QueryException::class);

        DB::table('categories')->where('id', $expense['category_id'])->delete();
    }

    public function test_french_category_names_are_unique_ignoring_case_and_surrounding_spaces(): void
    {
        Category::create(['name' => 'Déplacement']);
        $this->expectException(QueryException::class);

        Category::create(['name' => '  DÉPLACEMENT  ']);
    }

    public function test_private_attachments_have_no_anonymous_delivery_or_public_symlink(): void
    {
        $path = 'foundation/private-receipt.txt';
        $contents = 'Private foundation fixture';
        $disk = Storage::disk('attachments');

        try {
            $disk->put($path, $contents);

            $root = config('filesystems.disks.attachments.root');
            $this->assertFalse(str_starts_with($root, public_path().DIRECTORY_SEPARATOR));
            $this->assertFalse(is_link(public_path('storage')));
            $this->assertSame([], config('filesystems.links'));
            $this->get('/storage/'.$path)->assertNotFound()->assertDontSee($contents);
        } finally {
            $disk->delete($path);
        }
    }

    public function test_panel_login_is_available_in_french_without_registration_or_password_reset(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')
            ->assertOk()
            ->assertSee('Connexion')
            ->assertDontSee('Créer un compte')
            ->assertDontSee('Mot de passe oublié');
        $this->get('/register')->assertNotFound();
        $this->get('/password-reset/request')->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function roles(): array
    {
        return [
            'administrator' => ['administrator'],
            'editor' => ['editor'],
            'viewer' => ['viewer'],
        ];
    }

    #[DataProvider('roles')]
    public function test_panel_remains_closed_to_every_role_until_authentication_milestone(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get('/')->assertForbidden();
    }

    /** @return array{string, string, Application} */
    private function isolationFixture(): array
    {
        $fixture = base_path('.data/testing/guard-fixture-'.bin2hex(random_bytes(8)));
        $root = $fixture.'/.data/testing';
        mkdir($root, 0700, true);
        mkdir($fixture.'/outside', 0700);
        touch($root.'/database.sqlite');

        $application = new Application($fixture);
        Container::setInstance($this->app);
        $application->instance('env', 'testing');
        $application->instance('config', new Repository([
            'app' => ['url' => 'http://127.0.0.1'],
            'database' => ['connections' => ['sqlite' => ['database' => $root.'/database.sqlite']]],
        ]));

        return [$fixture, $root, $application];
    }

    /** @return array<string, mixed> */
    private function expenseAttributes(mixed $amount): array
    {
        return [
            'expense_date' => '2026-11-11',
            'amount_millimes' => $amount,
            'category_id' => Category::create(['name' => 'Fournitures'])->id,
            'created_by' => User::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
