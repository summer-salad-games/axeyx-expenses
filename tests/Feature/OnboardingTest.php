<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Auth\AccountSecurity;
use App\Filament\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Setup\Complete;
use App\Filament\Setup\Password;
use App\Models\Expense;
use App\Models\User;
use App\Security\AccountAccess;
use App\Security\AccountSessions;
use App\Services\AccountService;
use App\Services\AuditRecorder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use DatabaseMigrations;

    private const TEMPORARY_PASSWORD = 'TemporaryPassword!2026';

    private const CHOSEN_PASSWORD = 'une phrase personnelle avec des accents éè';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public static function roles(): array
    {
        return [['administrator'], ['editor'], ['viewer']];
    }

    #[DataProvider('roles')]
    public function test_mfa_complete_onboarding_session_can_only_change_password_or_log_out(string $role): void
    {
        $user = $this->pendingUser(Role::from($role));
        $this->assertFalse(app(AccountAccess::class)->allows($user));
        $this->assertFalse(Dashboard::canAccess());
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Expense::class));
        $this->get('/')->assertRedirect('/setup/password');
        $this->get('/setup')->assertRedirect('/setup/password');
        $this->get('/setup/password')->assertOk()->assertSee('Choisir votre mot de passe')
            ->assertDontSee('Régénérer les codes de récupération')->assertDontSee('Annuler');
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_mfa_is_required_before_the_onboarding_password_form(): void
    {
        $user = $this->pendingUser();
        $user->forceFill(['app_authentication_secret' => null])->save();
        app(AccountSessions::class)->establish($user, false);
        $this->get('/profile')->assertRedirect('/setup/mfa');
        Livewire::test(AccountSecurity::class)->assertForbidden();
    }

    public function test_recovery_regeneration_is_denied_before_password_onboarding(): void
    {
        $user = $this->pendingUser();
        $provider = Filament::getMultiFactorAuthenticationProviders()['app'];
        try {
            $provider->saveRecoveryCodes($user, $provider->generateRecoveryCodes());
            $this->fail('An onboarding session must not regenerate recovery codes.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertNull($user->fresh()->getAppAuthenticationRecoveryCodes());
    }

    public function test_empty_weak_mismatched_reused_or_unconfirmed_password_cannot_complete_onboarding(): void
    {
        $user = $this->pendingUser();
        foreach ([
            ['', '', self::TEMPORARY_PASSWORD, 'password'],
            ['weak', 'weak', self::TEMPORARY_PASSWORD, 'password'],
            [self::CHOSEN_PASSWORD, 'DifferentPassword!2026', self::TEMPORARY_PASSWORD, 'password'],
            [self::CHOSEN_PASSWORD, self::CHOSEN_PASSWORD, 'WrongPassword!2026', 'currentPassword'],
            [self::TEMPORARY_PASSWORD, self::TEMPORARY_PASSWORD, self::TEMPORARY_PASSWORD, 'password'],
        ] as [$password, $confirmation, $current, $error]) {
            Livewire::test(Password::class)->fillForm([
                'password' => $password, 'passwordConfirmation' => $confirmation, 'currentPassword' => $current,
            ])->set('data.must_change_password', false)->call('save')->assertHasFormErrors([$error]);
            $this->assertTrue($user->fresh()->must_change_password);
            $this->assertTrue(Hash::check(self::TEMPORARY_PASSWORD, $user->fresh()->password));
            $this->assertFalse(app(AccountAccess::class)->allows($user));
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_chosen_password_completes_onboarding_and_revokes_other_sessions(): void
    {
        $user = $this->pendingUser();
        $oldSession = session()->all();
        Livewire::test(Password::class)->fillForm([
            'password' => self::CHOSEN_PASSWORD, 'passwordConfirmation' => self::CHOSEN_PASSWORD,
            'currentPassword' => self::TEMPORARY_PASSWORD,
        ])->set('data.role', 'administrator')->set('data.onboarding_completed_at', '2000-01-01')
            ->call('save')->assertHasNoFormErrors()->assertRedirect('/setup/complete');
        $fresh = $user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertNull($fresh->onboarding_completed_at);
        $this->assertSame(Role::Viewer, $fresh->role);
        $this->assertTrue(Hash::check(self::CHOSEN_PASSWORD, $fresh->password));
        $this->assertFalse(app(AccountAccess::class)->allows($fresh));
        $this->get('/')->assertRedirect('/setup/complete');
        $this->get('/setup')->assertRedirect('/setup/complete');
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.password_changed', 'actor_id' => $user->id]);
        Livewire::test(Complete::class)->call('finish')->assertRedirect('/');
        $this->assertTrue(app(AccountAccess::class)->allows($fresh));
        $this->get('/')->assertOk();
        session()->flush();
        session()->put($oldSession);
        $this->get('/')->assertRedirect('/login');
    }

    public function test_cli_reset_keeps_mfa_and_requires_password_change_after_next_verified_login(): void
    {
        $user = $this->pendingUser();
        $user->forceFill(['must_change_password' => false])->save();
        $secret = $user->getAppAuthenticationSecret();
        app(AccountService::class)->changePassword($user, self::CHOSEN_PASSWORD, self::CHOSEN_PASSWORD);
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertSame($secret, $user->fresh()->getAppAuthenticationSecret());
        $this->get('/')->assertRedirect('/login');
        Auth::logout();
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => self::CHOSEN_PASSWORD])
            ->call('authenticate')->set('data.multiFactor.app.code', (new Google2FA)->getCurrentOtp($secret))
            ->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertRedirect('/setup/password');
    }

    public function test_completion_cannot_skip_password_or_use_a_revoked_session(): void
    {
        $user = $this->pendingUser();
        $this->get('/setup/complete')->assertRedirect('/setup/password');
        Livewire::test(Complete::class)->assertForbidden();
        Livewire::test(AccountSecurity::class)->assertForbidden();
        $user->forceFill(['must_change_password' => false])->save();
        $page = Livewire::test(Complete::class);
        app(AccountService::class)->disable($user);
        $page->call('finish')->assertForbidden();
        $this->assertNull($user->fresh()->onboarding_completed_at);
    }

    public function test_ready_step_is_resumable_and_completion_is_idempotent(): void
    {
        $user = $this->pendingUser();
        $user->forceFill(['must_change_password' => false])->save();
        $this->post('/logout');
        $this->actingAs($user->fresh());
        app(AccountSessions::class)->establish($user->fresh(), true);
        $this->get('/')->assertRedirect('/setup/complete');
        $this->get('/profile')->assertRedirect('/setup/complete');
        $page = Livewire::test(Complete::class);
        $page->call('finish')->assertRedirect('/');
        $page->call('finish')->assertRedirect('/');
        $this->assertNotNull($user->fresh()->onboarding_completed_at);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->get('/setup')->assertRedirect('/');
    }

    public function test_completion_migration_preserves_existing_finished_accounts_only(): void
    {
        $complete = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $noMfa = User::factory()->create();
        $temporary = $this->pendingUser();
        $migration = require database_path('migrations/2026_11_11_020000_add_onboarding_completion_to_users.php');
        $migration->down();
        $migration->up();
        $this->assertNotNull($complete->fresh()->onboarding_completed_at);
        $this->assertNull($noMfa->fresh()->onboarding_completed_at);
        $this->assertNull($temporary->fresh()->onboarding_completed_at);
    }

    public function test_migration_preserves_only_a_users_latest_self_chosen_password(): void
    {
        $neverChanged = User::factory()->create();
        $selfChanged = User::factory()->create();
        $resetLater = User::factory()->create();
        foreach ([$selfChanged, $resetLater] as $user) {
            app(AuditRecorder::class)->record('account.password_changed', $user, actor: $user);
        }
        app(AccountService::class)->changePassword($resetLater, self::CHOSEN_PASSWORD, self::CHOSEN_PASSWORD);
        $migration = require database_path('migrations/2026_11_11_010000_add_password_change_requirement_to_users.php');
        $migration->down();
        $migration->up();
        $this->assertTrue($neverChanged->fresh()->must_change_password);
        $this->assertFalse($selfChanged->fresh()->must_change_password);
        $this->assertTrue($resetLater->fresh()->must_change_password);
    }

    private function pendingUser(Role $role = Role::Viewer): User
    {
        $provider = Filament::getMultiFactorAuthenticationProviders()['app'];
        $user = User::factory()->create([
            'password' => self::TEMPORARY_PASSWORD, 'must_change_password' => true,
            'role' => $role,
            'onboarding_completed_at' => null,
            'app_authentication_secret' => $provider->generateSecret(),
        ]);
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, true);

        return $user;
    }
}
