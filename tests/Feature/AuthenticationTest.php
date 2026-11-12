<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Setup\Mfa as SetUpRequiredMultiFactorAuthentication;
use App\Models\User;
use App\Security\AccountAccess;
use App\Security\AccountSessions;
use App\Security\AppAuthentication;
use App\Services\AccountService;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use DatabaseMigrations;

    private const PASSWORD = 'StrongTestPassword!2026';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'file']);
        Cache::flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_password_login_requires_enrollment_before_application_access(): void
    {
        $user = $this->account(false);
        Livewire::test(Login::class)->fillForm(['email' => mb_strtoupper($user->email), 'password' => self::PASSWORD])
            ->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse(app(AccountAccess::class)->allows($user));
        $this->get('/')->assertRedirect('/setup/mfa');
        $this->get('/setup')->assertRedirect('/setup/mfa');
        $this->get('/setup/mfa')->assertOk();
        $this->get('/multi-factor-authentication/set-up')->assertRedirect('/setup');
    }

    public function test_enrolled_account_requires_valid_totp_and_rejects_replay(): void
    {
        $user = $this->account();
        $login = $this->beginLogin($user);
        $this->assertGuest();
        $login->set('data.multiFactor.app.code', 'invalid')->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.code']);
        $code = $this->code($user);
        $login->set('data.multiFactor.app.code', $code)->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(app(AccountAccess::class)->allows($user));
        $this->get('/')->assertOk();
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
        $this->assertNull(session('account_security'));
        $this->beginLogin($user)->set('data.multiFactor.app.code', $code)->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.code']);
        $this->assertGuest();
    }

    public function test_recovery_code_is_single_use_and_remaining_codes_are_encrypted_and_hashed(): void
    {
        $user = $this->account();
        $recovery = 'TestOnly-'.bin2hex(random_bytes(10));
        $user->forceFill(['app_authentication_recovery_codes' => [Hash::make($recovery)]])->save();
        $raw = $user->getRawOriginal('app_authentication_recovery_codes');
        $this->assertStringNotContainsString($recovery, $raw);
        $login = $this->beginLogin($user);
        $login->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $recovery)->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->assertSame([], $user->fresh()->getAppAuthenticationRecoveryCodes());
        $this->post('/logout');
        $this->beginLogin($user)->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $recovery)->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.recoveryCode']);
        $this->assertGuest();
    }

    public function test_disabled_account_cannot_log_in_with_correct_password(): void
    {
        $user = $this->account();
        $user->forceFill(['is_active' => false])->save();
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => self::PASSWORD])
            ->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    public function test_account_revoked_during_mfa_challenge_must_start_again(): void
    {
        $user = $this->account();
        $login = $this->beginLogin($user);
        app(AccountService::class)->resetMfa($user);
        $login->set('data.multiFactor.app.code', $this->code($user))->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    public function test_role_revoked_inside_final_mfa_request_cannot_adopt_new_version(): void
    {
        $user = $this->account();
        $login = $this->beginLogin($user);
        $attempts = 0;
        Event::listen(Attempting::class, function () use (&$attempts, $user): void {
            if (++$attempts === 2) {
                app(AccountService::class)->changeRole($user, Role::Editor);
            }
        });
        $login->set('data.multiFactor.app.code', $this->code($user))->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest();
        $this->assertNull(session('account_security'));
    }

    public function test_password_failures_are_rate_limited_and_never_create_a_session(): void
    {
        $user = $this->account();
        $login = Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'WrongPassword!2026']);
        for ($i = 0; $i < 5; $i++) {
            $login->call('authenticate')->assertHasFormErrors(['email']);
        }
        $login->call('authenticate')->assertNotified();
        $this->assertGuest();
    }

    public function test_mfa_attempts_are_rate_limited_even_across_new_components(): void
    {
        $user = $this->account();
        $login = $this->beginLogin($user);
        for ($i = 0; $i < 4; $i++) {
            $login->set('data.multiFactor.app.code', 'invalid')->call('authenticate')->assertHasErrors();
        }
        // Reset only the component/IP limiter to exercise the provider's per-user limiter.
        $limiter = MultiFactorChallenge::make();
        while (! $limiter->isRateLimited($user)) {
            $limiter->hitRateLimiter($user);
        }
        $login->set('data.multiFactor.app.code', $this->code($user))->call('authenticate')->assertNotified();
        $this->assertGuest();
    }

    public function test_ip_limit_bounds_attempts_even_when_the_email_changes(): void
    {
        RateLimiter::hit('auth.login.ip:'.hash('sha256', '127.0.0.1'), 60);
        for ($i = 1; $i < 60; $i++) {
            RateLimiter::hit('auth.login.ip:'.hash('sha256', '127.0.0.1'), 60);
        }
        Livewire::test(Login::class)
            ->fillForm(['email' => 'random@example.test', 'password' => self::PASSWORD])
            ->call('authenticate')->assertNotified();
        $this->assertGuest();
    }

    public function test_failed_authentication_logging_excludes_credentials(): void
    {
        Log::spy();
        $user = $this->account();
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'WrongPassword!2026'])
            ->call('authenticate')->assertHasFormErrors(['email']);
        $this->beginLogin($user)->set('data.multiFactor.app.code', 'invalid')->call('authenticate')->assertHasErrors();
        foreach (['password', 'mfa'] as $factor) {
            Log::shouldHaveReceived('notice')->with('authentication.failed', [
                'factor' => $factor, 'user_id' => $user->id, 'ip' => '127.0.0.1',
            ])->once();
        }
    }

    public function test_revoked_or_disabled_sessions_lose_access_on_next_request(): void
    {
        foreach (['disable', 'password', 'mfa'] as $operation) {
            $user = $this->account();
            $this->actingAs($user);
            app(AccountSessions::class)->establish($user, true);
            $this->get('/')->assertOk();
            match ($operation) {
                'disable' => app(AccountService::class)->disable($user),
                'password' => app(AccountService::class)->changePassword($user, 'ReplacementPassword!2026', 'ReplacementPassword!2026'),
                'mfa' => app(AccountService::class)->resetMfa($user),
            };
            $this->get('/')->assertRedirect('/login');
            $this->assertGuest();
        }
    }

    public function test_livewire_actions_cannot_use_a_revoked_session(): void
    {
        $user = $this->account();
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, true);
        app(AccountService::class)->disable($user);
        $this->post(route('default-livewire.update', absolute: false), ['components' => []])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_remember_me_and_mfa_disable_are_not_available(): void
    {
        $this->get('/login')->assertDontSee('Se souvenir de moi');
        $user = $this->account();
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, true);
        $provider = $this->provider();
        $names = array_map(fn ($action) => $action->getName(), $provider->getActions());
        $this->assertNotContains('disableAppAuthentication', $names);
        $this->get('/profile')->assertDontSee('Désactiver');
    }

    public function test_dashboard_has_explicit_account_authorization(): void
    {
        $this->assertFalse(Dashboard::canAccess());
        $user = $this->account();
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, false);
        $this->assertFalse(Dashboard::canAccess());
        app(AccountSessions::class)->establish($user, true);
        $this->assertTrue(Dashboard::canAccess());
        app(AccountService::class)->disable($user);
        $this->assertFalse(Dashboard::canAccess());
    }

    public function test_enrollment_requires_password_and_verification_and_establishes_mfa_session(): void
    {
        $user = $this->account(false);
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, false);
        $page = Livewire::test(SetUpRequiredMultiFactorAuthentication::class);
        $arguments = decrypt($page->get('mountedActions')[0]['arguments']['encrypted']);
        $page->set('mountedActions.0.data.password', self::PASSWORD)
            ->set('mountedActions.0.data.code', (new Google2FA)->getCurrentOtp($arguments['secret']))
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertNotNull($user->fresh()->getAppAuthenticationSecret());
        $this->assertTrue(app(AccountAccess::class)->allows($user));
        $this->assertCount(8, $user->fresh()->getAppAuthenticationRecoveryCodes());
        $this->get('/')->assertOk();
    }

    public function test_initial_enrollment_requires_a_valid_code_without_repeating_the_temporary_password(): void
    {
        $user = $this->account(false);
        $user->forceFill(['must_change_password' => true, 'onboarding_completed_at' => null])->save();
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, false);
        $page = Livewire::test(SetUpRequiredMultiFactorAuthentication::class);
        $arguments = decrypt($page->get('mountedActions')[0]['arguments']['encrypted']);
        $code = (new Google2FA)->getCurrentOtp($arguments['secret']);
        $page->set('mountedActions.0.data.code', $code === '000000' ? '111111' : '000000')
            ->callMountedAction()->assertHasActionErrors(['code'])
            ->assertSee(__('filament-panels::auth/multi-factor/app/actions/set-up.modal.form.code.messages.invalid'))
            ->assertSee('setup-card')->assertSee('Se déconnecter');
        $this->assertNull($user->fresh()->getAppAuthenticationSecret());
        $page->set('mountedActions.0.data.code', (new Google2FA)->getCurrentOtp($arguments['secret']))
            ->callMountedAction()->assertHasNoActionErrors()->assertRedirect('/setup/password');
        $this->assertNotNull($user->fresh()->getAppAuthenticationSecret());
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertFalse(app(AccountAccess::class)->allows($user->fresh()));
    }

    public function test_initial_enrollment_keeps_the_native_code_attempt_budget(): void
    {
        $user = $this->account(false);
        $user->forceFill(['must_change_password' => true, 'onboarding_completed_at' => null])->save();
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, false);
        $page = Livewire::test(SetUpRequiredMultiFactorAuthentication::class);
        $arguments = decrypt($page->get('mountedActions')[0]['arguments']['encrypted']);
        $key = 'filament:auth:set_up_app_authentication:rate_limit:'.Filament::getUserScopedAuthIdentifier();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            RateLimiter::hit($key);
        }
        $page->set('mountedActions.0.data.code', (new Google2FA)->getCurrentOtp($arguments['secret']))
            ->callMountedAction()->assertHasActionErrors(['code']);
        $this->assertNull($user->fresh()->getAppAuthenticationSecret());
    }

    public function test_mfa_reset_still_requires_password_even_while_password_onboarding_is_pending(): void
    {
        $user = $this->account();
        $user->forceFill(['must_change_password' => true, 'onboarding_completed_at' => null])->save();
        app(AccountService::class)->resetMfa($user);
        $this->actingAs($user->fresh());
        app(AccountSessions::class)->establish($user->fresh(), false);
        $page = Livewire::test(SetUpRequiredMultiFactorAuthentication::class);
        $arguments = decrypt($page->get('mountedActions')[0]['arguments']['encrypted']);
        $page->set('mountedActions.0.data.code', (new Google2FA)->getCurrentOtp($arguments['secret']))
            ->callMountedAction()->assertHasActionErrors(['password']);
        $this->assertNull($user->fresh()->getAppAuthenticationSecret());
    }

    public function test_invalid_enrollment_credentials_cannot_save_mfa_state(): void
    {
        $user = $this->account(false);
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, false);
        $page = Livewire::test(SetUpRequiredMultiFactorAuthentication::class);
        $arguments = decrypt($page->get('mountedActions')[0]['arguments']['encrypted']);
        $page->set('mountedActions.0.data.password', 'WrongPassword!2026')
            ->set('mountedActions.0.data.code', (new Google2FA)->getCurrentOtp($arguments['secret']))
            ->callMountedAction()->assertHasActionErrors(['password']);
        $page->set('mountedActions.0.data.password', self::PASSWORD)
            ->set('mountedActions.0.data.code', 'invalid')
            ->callMountedAction()->assertHasActionErrors(['code']);
        $this->assertNull($user->fresh()->getAppAuthenticationSecret());
        $this->assertFalse(app(AccountAccess::class)->allows($user));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_two_processes_cannot_consume_the_same_recovery_code(): void
    {
        $user = $this->account();
        $code = 'Concurrent-'.bin2hex(random_bytes(10));
        $user->forceFill(['app_authentication_recovery_codes' => [Hash::make($code)]])->save();
        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/mfa-worker.php')], base_path(), [
                'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
                'APP_URL' => 'http://127.0.0.1', 'APP_CONFIG_CACHE' => base_path('.data/testing/config.php'),
                'ARGON_MEMORY' => '1024', 'ARGON_TIME' => '1',
            ]);
            $process->setInput(json_encode(['user_id' => $user->getKey(), 'code' => $code], JSON_THROW_ON_ERROR));
            $process->start();
            $processes[] = $process;
        }
        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $outcomes[] = trim($process->getOutput());
        }
        sort($outcomes);
        $this->assertSame(['0', '1'], $outcomes);
    }

    private function account(bool $enrolled = true): User
    {
        return User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            'app_authentication_secret' => $enrolled ? $this->provider()->generateSecret() : null,
        ]);
    }

    private function beginLogin(User $user): Testable
    {
        Auth::logout();
        session()->forget('account_security');

        return Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => self::PASSWORD])
            ->call('authenticate')->assertHasNoFormErrors();
    }

    private function provider(): AppAuthentication
    {
        return Filament::getMultiFactorAuthenticationProviders()['app'];
    }

    private function code(User $user): string
    {
        return (new Google2FA)->getCurrentOtp($user->getAppAuthenticationSecret());
    }
}
