<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\CategoryTransactions;
use App\Filament\Widgets\CreatorTransactions;
use App\Filament\Widgets\TransactionOverview;
use App\Filament\Widgets\TransactionTimeline;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Security\AccountSessions;
use App\Services\TransactionStatistics;
use App\Support\ReportingPeriod;
use App\Support\TndAmount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-13 12:00:00', 'Europe/Luxembourg'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function login(string $role = 'administrator'): User
    {
        $user = User::factory()->create(['role' => Role::from($role), 'app_authentication_secret' => 'TESTONLYSECRET']);
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, true);

        return $user;
    }

    private function report(User $actor, string $from, string $until): array
    {
        return app(TransactionStatistics::class)->report($actor, ['preset' => 'custom', 'from' => $from, 'until' => $until]);
    }

    public static function presets(): array
    {
        return [
            ['2026-11-13T12:00:00Z', 'today', '2026-11-13', '2026-11-13'],
            ['2026-11-15T12:00:00Z', 'week', '2026-11-09', '2026-11-15'],
            ['2026-11-16T12:00:00Z', 'week', '2026-11-16', '2026-11-22'],
            ['2026-11-30T23:30:00Z', 'month', '2026-12-01', '2026-12-31'],
            ['2026-12-31T23:30:00Z', 'year', '2027-01-01', '2027-12-31'],
            ['2026-01-01T12:00:00Z', 'last_month', '2025-12-01', '2025-12-31'],
            ['2024-03-01T12:00:00Z', 'last_month', '2024-02-01', '2024-02-29'],
            ['2026-10-24T23:30:00Z', 'today', '2026-10-25', '2026-10-25'],
            ['2026-03-28T23:30:00Z', 'week', '2026-03-23', '2026-03-29'],
        ];
    }

    #[DataProvider('presets')]
    public function test_presets_use_luxembourg_calendar_dates_and_monday_weeks(string $now, string $preset, string $from, string $until): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($now));
        $period = ReportingPeriod::resolve(['preset' => $preset]);
        $this->assertSame($from, $period->from);
        $this->assertSame($until, $period->until);
    }

    public static function invalidPeriods(): array
    {
        return [
            [['preset' => 'unknown']], [['preset' => ['month']]], [['from' => '2026-01-01']],
            [['preset' => 'custom']],
            [['preset' => 'custom', 'from' => '2026-02-30', 'until' => '2026-03-01']],
            [['preset' => 'custom', 'from' => '2026-01-02', 'until' => '2026-01-01']],
            [['preset' => 'custom', 'from' => ['2026-01-01'], 'until' => '2026-01-01']],
            [['preset' => 'custom', 'from' => '2026-01-01', 'until' => "2026-12-31' OR 1=1 --"]],
            [['preset' => 'custom', 'from' => '1900-01-01', 'until' => '2100-01-01']],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_periods_are_rejected(array $filters): void
    {
        $this->expectException(ValidationException::class);
        ReportingPeriod::resolve($filters);
    }

    public static function roles(): array
    {
        return [['administrator'], ['editor'], ['viewer']];
    }

    #[DataProvider('roles')]
    public function test_shared_totals_all_groupings_and_counts_are_exact_for_every_role(string $role): void
    {
        $actor = $this->login($role);
        $other = User::factory()->create(['name' => 'Personne inactive', 'is_active' => false]);
        $a = Category::factory()->create(['name' => 'Actif']);
        $b = Category::factory()->create(['name' => 'Désactivé', 'is_active' => false]);
        foreach ([['2026-10-31', 9, $a, $actor], ['2026-11-01', 1001, $a, $actor], ['2026-11-15', 2002, $a, $other], ['2026-11-30', 3003, $b, $actor], ['2026-12-01', 4004, $a, $actor], ['2026-12-31', 5005, $b, $other]] as [$date, $amount, $category, $creator]) {
            $transaction = Transaction::factory()->create(['transaction_date' => $date, 'amount_millimes' => $amount, 'category_id' => $category->id, 'created_by' => $creator->id]);
            if ($amount === 1001) {
                Attachment::factory()->count(3)->create(['transaction_id' => $transaction->id]);
            }
        }
        $deleted = Transaction::factory()->create(['transaction_date' => '2026-11-01', 'amount_millimes' => 999]);
        $deleted->delete();
        $report = $this->report($actor, '2026-11-01', '2026-12-31');
        $this->assertSame('15015', $report['expense_millimes']);
        $this->assertSame('15,015 TND', $report['expense_formatted']);
        $this->assertSame(5, $report['count']);
        $this->assertSame(['6006', '9009'], array_column($report['timeline'], 'expense_millimes'));
        $this->assertSame(['8008', '7007'], array_column($report['expense_categories'], 'millimes'));
        $this->assertSame(['8008', '7007'], array_column($report['creators'], 'expense_millimes'));
        $this->assertSame('Désactivé', $report['expense_categories'][0]['label']);
        $this->assertSame('Personne inactive', $report['creators'][1]['label']);
        $single = $this->report($actor, '2026-11-01', '2026-11-01');
        $this->assertSame('1001', $single['expense_millimes']);
        $this->assertSame(1, $single['count']);
        $this->get('/')->assertOk()->assertSee('Net de la période');
    }

    #[DataProvider('roles')]
    public function test_revenue_expense_and_signed_net_share_exact_buckets_without_attachment_multiplication(string $role): void
    {
        $actor = $this->login($role);
        $expense = Category::factory()->create(['name' => 'Commun']);
        $revenue = Category::factory()->create(['name' => 'Commun', 'type' => 'revenue']);
        foreach ([['expense', 1001, $expense, '2026-11-01'], ['revenue', 3003, $revenue, '2026-11-01'], ['expense', 4004, $expense, '2026-11-02']] as [$type, $amount, $category, $date]) {
            $transaction = Transaction::factory()->create(['type' => $type, 'amount_millimes' => $amount, 'category_id' => $category->id, 'created_by' => $actor->id, 'transaction_date' => $date]);
            Attachment::factory()->count(2)->create(['transaction_id' => $transaction->id]);
        }
        $report = $this->report($actor, '2026-11-01', '2026-11-03');
        $this->assertSame(['5005', '3003', '-2002', 3], [$report['expense_millimes'], $report['revenue_millimes'], $report['net_millimes'], $report['count']]);
        $this->assertSame('−2,002 TND', $report['net_formatted']);
        $this->assertSame(['2002', '-4004', '0'], array_column($report['timeline'], 'net_millimes'));
        $this->assertSame(['5005'], array_column($report['expense_categories'], 'millimes'));
        $this->assertSame(['3003'], array_column($report['revenue_categories'], 'millimes'));
        $this->assertSame('-2002', $report['creators'][0]['net_millimes']);
        $positive = $this->report($actor, '2026-11-01', '2026-11-01');
        $this->assertSame('2,002 TND', $positive['net_formatted']);
        Transaction::factory()->create(['type' => 'revenue', 'amount_millimes' => 2002, 'category_id' => $revenue->id, 'transaction_date' => '2026-11-03']);
        $this->assertSame('0,000 TND', $this->report($actor, '2026-11-01', '2026-11-03')['net_formatted']);
    }

    public function test_empty_and_missing_months_are_zero_filled(): void
    {
        $actor = $this->login();
        $empty = $this->report($actor, '2024-02-29', '2024-04-01');
        $this->assertSame('0,000 TND', $empty['expense_formatted']);
        $this->assertSame(['0', '0', '0'], array_column($empty['timeline'], 'expense_millimes'));
        $this->assertSame([], $empty['expense_categories']);
        Transaction::factory()->create(['transaction_date' => '2024-02-29', 'amount_millimes' => 1]);
        Transaction::factory()->create(['transaction_date' => '2024-04-01', 'amount_millimes' => 999]);
        $report = $this->report($actor, '2024-02-29', '2024-04-01');
        $this->assertSame(['1', '0', '999'], array_column($report['timeline'], 'expense_millimes'));
        $this->assertSame([1, 0, 1], array_column($report['timeline'], 'count'));
        $this->assertSame('1000', $report['expense_millimes']);
    }

    public static function timelineRanges(): array
    {
        return [
            ['2026-01-01', '2026-01-31', 'day', 31],
            ['2026-01-01', '2026-02-01', 'month', 2],
            ['2024-02-01', '2024-02-29', 'day', 29],
            ['2026-12-31', '2027-01-01', 'day', 2],
            ['2026-03-23', '2026-03-29', 'day', 7],
            ['2026-10-19', '2026-10-25', 'day', 7],
        ];
    }

    #[DataProvider('timelineRanges')]
    public function test_timeline_granularity_and_calendar_buckets(string $from, string $until, string $granularity, int $count): void
    {
        $actor = $this->login();
        $report = $this->report($actor, $from, $until);
        $this->assertSame($granularity, $report['granularity']);
        $this->assertCount($count, $report['timeline']);
        $this->assertSame(array_fill(0, $count, '0'), array_column($report['timeline'], 'expense_millimes'));
        if ($granularity === 'day') {
            $this->assertSame($from, $report['timeline'][0]['id']);
            $this->assertSame($until, $report['timeline'][$count - 1]['id']);
        }
    }

    public function test_daily_totals_counts_labels_and_zero_filling_share_the_selected_bounds(): void
    {
        $actor = $this->login();
        foreach (['2026-11-08' => 999, '2026-11-09' => 1001, '2026-11-11' => 2002, '2026-11-15' => 3003, '2026-11-16' => 888] as $date => $amount) {
            Transaction::factory()->create(['transaction_date' => $date, 'amount_millimes' => $amount]);
        }
        $report = app(TransactionStatistics::class)->report($actor, ['preset' => 'week']);
        $this->assertSame('day', $report['granularity']);
        $this->assertSame('6006', $report['expense_millimes']);
        $this->assertSame(3, $report['count']);
        $this->assertSame(['1001', '0', '2002', '0', '0', '0', '3003'], array_column($report['timeline'], 'expense_millimes'));
        $this->assertSame([1, 0, 1, 0, 0, 0, 1], array_column($report['timeline'], 'count'));
        $this->assertSame('09/11/2026', $report['timeline'][0]['label']);
        $this->assertSame('09/11', $report['timeline'][0]['axis_label']);
        Livewire::test(TransactionTimeline::class, ['statistics' => $report])->assertSee('Transactions par jour')->assertSee('1,001 TND');
        Transaction::factory()->create(['transaction_date' => '2026-11-13', 'amount_millimes' => 4004]);
        $today = app(TransactionStatistics::class)->report($actor, ['preset' => 'today']);
        $this->assertCount(1, $today['timeline']);
        $this->assertSame('4004', $today['timeline'][0]['expense_millimes']);
    }

    public function test_period_action_validates_and_persists_one_shared_period_across_refreshes(): void
    {
        $this->login();
        Transaction::factory()->create(['transaction_date' => '2026-11-01', 'amount_millimes' => 1234]);
        $page = Livewire::test(Dashboard::class)
            ->callAction('period', data: ['preset' => 'custom', 'from' => '2026-12-01', 'until' => '2026-01-01'])
            ->assertHasActionErrors(['until']);
        $this->assertSame('1234', $page->instance()->report['expense_millimes']);
        $page->setActionData(['preset' => 'custom', 'from' => '2026-01-01', 'until' => '2026-12-31'])->callMountedAction()
            ->assertHasNoActionErrors()->assertSet('filters.preset', 'custom');
        $expected = $page->instance()->report;
        $page->call('$refresh');
        $this->assertSame($expected, $page->instance()->report);
        $reloaded = Livewire::test(Dashboard::class);
        $this->assertSame('custom', $reloaded->get('filters')['preset']);
        $this->assertSame($expected, $reloaded->instance()->report);
        $page->callAction('period', data: ['preset' => 'last_month'])->assertHasNoActionErrors();
        $this->assertSame('2026-10-01', $page->instance()->report['from']);
    }

    public function test_forged_filters_show_unavailable_without_broadening_the_report(): void
    {
        $this->login();
        Transaction::factory()->create(['amount_millimes' => 987654321]);
        Livewire::test(Dashboard::class)->set('filters', ['preset' => ['year']])->assertSee('Période invalide')
            ->assertDontSee(TndAmount::format(987654321));
        $this->get('/?filters[preset]=custom&filters[from]=2026-02-30&filters[until]=2026-12-31')->assertOk()->assertSee('Période invalide');
    }

    public function test_widgets_share_one_consistent_sqlite_read_snapshot(): void
    {
        $actor = $this->login();
        $transaction = Transaction::factory()->create(['transaction_date' => '2026-11-13', 'amount_millimes' => 1234]);
        config(['database.connections.racer' => config('database.connections.sqlite')]);
        $raced = false;
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($transaction, &$raced): void {
            if (! $raced && str_contains($query->sql, 'COALESCE(SUM(CASE WHEN type')) {
                $raced = true;
                DB::connection('racer')->table('transactions')->where('id', $transaction->id)->update(['amount_millimes' => 9999]);
            }
        });
        $report = app(TransactionStatistics::class)->report($actor);
        $this->assertTrue($raced);
        $this->assertSame('1234', $report['expense_millimes']);
        foreach (['timeline', 'expense_categories', 'creators'] as $group) {
            $this->assertSame(1234, array_sum(array_column($report[$group], $group === 'expense_categories' ? 'millimes' : 'expense_millimes')));
        }
        $this->assertSame('9999', app(TransactionStatistics::class)->report($actor)['expense_millimes']);
    }

    public function test_large_exact_totals_are_transmitted_to_charts_as_strings(): void
    {
        $actor = $this->login();
        $category = Category::factory()->create();
        $row = ['type' => 'expense', 'transaction_date' => '2026-11-13', 'amount_millimes' => TndAmount::MAX_MILLIMES, 'category_id' => $category->id, 'created_by' => $actor->id];
        foreach (array_chunk(array_fill(0, 9008, $row), 400) as $batch) {
            DB::table('transactions')->insert($batch);
        }
        DB::table('transactions')->insert([...$row, 'amount_millimes' => 1]);
        $total = 9008 * TndAmount::MAX_MILLIMES + 1;
        $report = app(TransactionStatistics::class)->report($actor);
        $this->assertSame((string) $total, $report['expense_millimes']);
        $widget = Livewire::test(TransactionTimeline::class, ['statistics' => $report]);
        $this->assertContains((string) $total, (new \ReflectionMethod(TransactionTimeline::class, 'getCachedData'))->invoke($widget->instance())['datasets'][1]['data']);
        $widget->assertSee(TndAmount::format($total));
    }

    public function test_native_sqlite_overflow_is_reported_without_float_fallback(): void
    {
        $actor = $this->login();
        $transaction = Transaction::factory()->create(['transaction_date' => '2026-11-13', 'amount_millimes' => 1]);
        Transaction::factory()->create(['transaction_date' => '2026-11-13', 'amount_millimes' => 1]);
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('transactions')->where('id', $transaction->id)->update(['amount_millimes' => PHP_INT_MAX]);
            try {
                app(TransactionStatistics::class)->report($actor);
                $this->fail('Expected integer overflow.');
            } catch (OverflowException) {
                $page = Livewire::test(Dashboard::class);
                $page->assertSee('Un total dépasse la limite de calcul');
                $this->assertArrayHasKey('error', $page->instance()->report);
            }
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
    }

    public function test_incomplete_and_revoked_sessions_cannot_fetch_reports_or_mount_widgets(): void
    {
        $actor = $this->login();
        app(AccountSessions::class)->establish($actor, false);
        foreach ([Dashboard::class, TransactionOverview::class, TransactionTimeline::class, CategoryTransactions::class, CreatorTransactions::class] as $component) {
            Livewire::test($component)->assertForbidden();
        }
        try {
            app(TransactionStatistics::class)->report($actor);
            $this->fail('Incomplete MFA report allowed.');
        } catch (AuthorizationException) {
            $this->assertFalse(Dashboard::canAccess());
        }
        app(AccountSessions::class)->establish($actor, true);
        $widget = Livewire::test(TransactionTimeline::class);
        $actor->forceFill(['is_active' => false])->save();
        $widget->call('updateChartData')->assertForbidden();
    }
}
