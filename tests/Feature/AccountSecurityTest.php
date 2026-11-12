<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Auth\AccountSecurity;
use App\Models\AuditLog;
use App\Models\User;
use App\Security\AccountAccess;
use App\Security\AccountSessions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use DatabaseMigrations;

    private const CURRENT_PASSWORD = 'CurrentPassword!2026';

    private const NEW_PASSWORD = 'une phrase personnelle avec des accents éè';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Cache::flush();
    }

    public function test_security_profile_exposes_only_password_fields(): void
    {
        $this->authenticatedUser();

        Livewire::test(AccountSecurity::class)
            ->assertOk()
            ->assertSee('Paramètres du compte')
            ->assertFormFieldExists('password')
            ->assertFormFieldDoesNotExist('name')
            ->assertFormFieldDoesNotExist('email')
            ->assertFormFieldDoesNotExist('role')
            ->assertFormFieldDoesNotExist('is_active');
    }

    public function test_password_change_requires_current_credentials(): void
    {
        $user = $this->authenticatedUser();

        Livewire::test(AccountSecurity::class)
            ->fillForm($this->passwordForm('WrongPassword!2026'))
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $user->fresh()->password));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_password_change_requires_strength_and_matching_confirmation(): void
    {
        $user = $this->authenticatedUser();

        Livewire::test(AccountSecurity::class)
            ->fillForm([
                'password' => 'short',
                'passwordConfirmation' => 'short',
                'currentPassword' => self::CURRENT_PASSWORD,
            ])
            ->call('save')
            ->assertHasFormErrors(['password']);

        Livewire::test(AccountSecurity::class)
            ->fillForm([
                'password' => self::NEW_PASSWORD,
                'passwordConfirmation' => 'DifferentPassword!2026',
                'currentPassword' => self::CURRENT_PASSWORD,
            ])
            ->call('save')
            ->assertHasFormErrors(['password']);

        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $user->fresh()->password));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_password_change_rejects_identity_tampering_and_revokes_previous_sessions(): void
    {
        Mail::fake();
        $user = $this->authenticatedUser();
        $oldSession = session()->all();
        $oldVersion = (int) $user->auth_version;
        $oldRememberToken = $user->remember_token;

        Livewire::test(AccountSecurity::class)
            ->fillForm($this->passwordForm())
            ->set('data.name', 'Forged name')
            ->set('data.email', 'forged@example.test')
            ->set('data.role', 'administrator')
            ->set('data.is_active', false)
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $user->fresh();
        $this->assertSame($user->name, $fresh->name);
        $this->assertSame($user->email, $fresh->email);
        $this->assertSame($user->role, $fresh->role);
        $this->assertTrue($fresh->is_active);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertSame($oldVersion + 1, (int) $fresh->auth_version);
        $this->assertNotSame($oldRememberToken, $fresh->remember_token);
        $this->assertTrue(app(AccountAccess::class)->allows($fresh));

        $audit = AuditLog::query()->sole();
        $this->assertSame('account.password_changed', $audit->action);
        $this->assertSame($user->id, $audit->actor_id);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, json_encode($audit->metadata));
        $this->assertStringNotContainsString(self::CURRENT_PASSWORD, json_encode($audit->metadata));
        Mail::assertNothingSent();

        session()->flush();
        session()->put($oldSession);
        $this->assertFalse(app(AccountAccess::class)->allows($fresh));
    }

    public function test_security_profile_denies_an_incomplete_mfa_session(): void
    {
        $user = $this->authenticatedUser();
        app(AccountSessions::class)->establish($user, false);

        Livewire::test(AccountSecurity::class)->assertForbidden();
    }

    public function test_recovery_regeneration_requires_credentials_and_revokes_other_sessions(): void
    {
        $user = $this->authenticatedUser();
        $oldSession = session()->all();
        $action = TestAction::make('regenerateAppAuthenticationRecoveryCodes')->schemaComponent('app', 'content');
        $page = Livewire::test(AccountSecurity::class)->mountAction($action);
        $page->set('mountedActions.0.data.password', 'WrongPassword!2026')->callMountedAction()
            ->assertHasActionErrors(['password']);
        $this->assertDatabaseCount('audit_logs', 0);

        $page->set('mountedActions.0.data.password', self::CURRENT_PASSWORD)->callMountedAction()
            ->assertHasNoActionErrors();
        $fresh = $user->fresh();
        $this->assertCount(8, $fresh->getAppAuthenticationRecoveryCodes());
        $this->assertTrue(app(AccountAccess::class)->allows($fresh));
        $this->assertSame('account.recovery_codes_regenerated', AuditLog::query()->sole()->action);
        session()->flush();
        session()->put($oldSession);
        $this->assertFalse(app(AccountAccess::class)->allows($fresh));
    }

    /** @return array<string, string> */
    private function passwordForm(string $currentPassword = self::CURRENT_PASSWORD): array
    {
        return [
            'password' => self::NEW_PASSWORD,
            'passwordConfirmation' => self::NEW_PASSWORD,
            'currentPassword' => $currentPassword,
        ];
    }

    private function authenticatedUser(): User
    {
        $user = User::factory()->create(['password' => self::CURRENT_PASSWORD]);
        $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, true);

        return $user;
    }
}
