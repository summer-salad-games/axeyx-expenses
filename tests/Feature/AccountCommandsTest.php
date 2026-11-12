<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountCommandsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_create_prompts_for_hidden_confirmed_password_and_normalizes_account_data(): void
    {
        $password = $this->password();

        $this->artisan('accounts:create')
            ->expectsQuestion('Nom', '  Médecin de test  ')
            ->expectsQuestion('Adresse e-mail', '  New.User@EXAMPLE.TEST  ')
            ->expectsChoice('Rôle', 'viewer', ['administrator', 'editor', 'viewer'])
            ->expectsQuestion('Mot de passe', $password)
            ->expectsQuestion('Confirmez le mot de passe', $password)
            ->doesntExpectOutputToContain($password)
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('Médecin de test', $user->name);
        $this->assertSame('new.user@example.test', $user->email);
        $this->assertSame(Role::Viewer, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->app_authentication_secret);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertAudit('account.created', $user, $password);
    }

    public function test_account_service_defaults_to_viewer_and_rejects_case_variant_duplicate_email(): void
    {
        $password = $this->password();
        $accounts = app(AccountService::class);
        $user = $accounts->create('Premier', 'Same@EXAMPLE.TEST', $password, $password);
        $this->assertSame(Role::Viewer, $user->role);

        $this->artisan('accounts:create')
            ->expectsQuestion('Nom', 'Deuxième')
            ->expectsQuestion('Adresse e-mail', 'same@example.test')
            ->expectsChoice('Rôle', 'viewer', ['administrator', 'editor', 'viewer'])
            ->expectsQuestion('Mot de passe', $password)
            ->expectsQuestion('Confirmez le mot de passe', $password)
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidPasswords(): array
    {
        return [
            'too short' => ['Ab1!', 'Ab1!'],
            'eleven characters' => ['abcdefghijk', 'abcdefghijk'],
            'confirmation mismatch' => ['LongPassword123!', 'DifferentPassword123!'],
        ];
    }

    public function test_account_creation_and_cli_reset_accept_passwords_without_composition_requirements(): void
    {
        $accounts = app(AccountService::class);
        $user = $accounts->create('Compte de test', 'passphrase@example.test', 'douzelettres', 'douzelettres');
        $this->assertTrue(Hash::check('douzelettres', $user->password));
        $phrase = 'une phrase personnelle avec des accents éè';
        $accounts->changePassword($user, $phrase, $phrase);
        $this->assertTrue(Hash::check($phrase, $user->fresh()->password));
    }

    #[DataProvider('invalidPasswords')]
    public function test_create_rejects_weak_or_unconfirmed_password_without_persisting_data(string $password, string $confirmation): void
    {
        $this->artisan('accounts:create')
            ->expectsQuestion('Nom', 'Compte invalide')
            ->expectsQuestion('Adresse e-mail', 'invalid@example.test')
            ->expectsChoice('Rôle', 'viewer', ['administrator', 'editor', 'viewer'])
            ->expectsQuestion('Mot de passe', $password)
            ->expectsQuestion('Confirmez le mot de passe', $confirmation)
            ->doesntExpectOutputToContain($password)
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_password_change_uses_hidden_confirmation_and_revokes_existing_sessions(): void
    {
        $user = $this->revocableUser();
        $password = $this->password();

        $this->artisan('accounts:password', ['user' => $user->email])
            ->expectsQuestion('Mot de passe', $password)
            ->expectsQuestion('Confirmez le mot de passe', $password)
            ->doesntExpectOutputToContain($password)
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertSame(8, $user->auth_version);
        $this->assertTrue($user->must_change_password);
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertAudit('account.password_changed', $user, $password);
    }

    public function test_role_change_resolves_normalized_email_and_preserves_unrelated_fields(): void
    {
        $user = $this->revocableUser();
        $hash = $user->password;

        $this->artisan('accounts:role', ['user' => strtoupper($user->email), 'role' => 'editor'])->assertSuccessful();

        $user->refresh();
        $this->assertSame(Role::Editor, $user->role);
        $this->assertSame($hash, $user->password);
        $this->assertTrue($user->is_active);
        $this->assertSame(8, $user->auth_version);
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertAudit('account.role_changed', $user, $hash);
        $audit = AuditLog::query()->sole();
        $this->assertSame('viewer', $audit->metadata['from_role']);
        $this->assertSame('editor', $audit->metadata['to_role']);
    }

    public function test_disable_keeps_history_identity_but_revokes_access(): void
    {
        $user = $this->revocableUser();

        $this->artisan('accounts:disable', ['user' => $user->getKey()])->assertSuccessful();

        $user->refresh();
        $this->assertFalse($user->is_active);
        $this->assertSame(8, $user->auth_version);
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertDatabaseHas('users', ['id' => $user->getKey()]);
        $this->assertAudit('account.disabled', $user);
    }

    public function test_mfa_reset_requires_confirmation_and_then_clears_enrollment_and_recovery(): void
    {
        $user = $this->revocableUser();
        $user->forceFill([
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
            'app_authentication_recovery_codes' => ['fixture-recovery-code'],
        ])->save();
        $question = 'Réinitialiser la configuration TOTP et les codes de récupération de ce compte ?';

        $this->artisan('accounts:mfa-reset', ['user' => $user->getKey()])
            ->expectsConfirmation($question, 'no')
            ->expectsOutput('Opération annulée.')
            ->assertSuccessful();
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()->app_authentication_secret);
        $this->assertSame(7, $user->fresh()->auth_version);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->artisan('accounts:mfa-reset', ['user' => $user->getKey()])
            ->expectsConfirmation($question, 'yes')
            ->assertSuccessful();
        $user->refresh();
        $this->assertNull($user->app_authentication_secret);
        $this->assertNull($user->app_authentication_recovery_codes);
        $this->assertSame(8, $user->auth_version);
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertAudit('account.mfa_reset', $user, 'JBSWY3DPEHPK3PXP');
        $this->assertStringNotContainsString('fixture-recovery-code', AuditLog::query()->sole()->getRawOriginal('metadata'));
    }

    public function test_list_shows_only_non_secret_account_information(): void
    {
        $user = $this->revocableUser();
        $user->forceFill([
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
            'app_authentication_recovery_codes' => ['fixture-recovery-code'],
        ])->save();

        $this->assertSame(0, Artisan::call('accounts:list'));
        $output = Artisan::output();
        $this->assertStringContainsString($user->email, $output);
        $this->assertStringContainsString('viewer', $output);
        $this->assertStringContainsString('configuré', $output);
        foreach ([$user->password, 'old-remember-token', 'JBSWY3DPEHPK3PXP', 'fixture-recovery-code'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_invalid_role_and_missing_user_fail_without_mutating_or_auditing(): void
    {
        $user = $this->revocableUser();

        $this->artisan('accounts:role', ['user' => $user->getKey(), 'role' => 'owner'])
            ->expectsOutput('Rôle invalide : administrator, editor ou viewer.')
            ->assertFailed();
        $this->artisan('accounts:disable', ['user' => 'absent@example.test'])
            ->expectsOutput('Utilisateur introuvable.')
            ->assertFailed();
        $this->assertSame(Role::Viewer, $user->fresh()->role);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertSame(7, $user->fresh()->auth_version);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_account_creation(): void
    {
        DB::statement("CREATE TRIGGER reject_account_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'fixture audit failure'); END");
        $password = $this->password();

        try {
            app(AccountService::class)->create('Compte', 'atomic@example.test', $password, $password);
            $this->fail('Expected the audit failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('fixture audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_account_commands_do_not_define_plaintext_password_options_or_arguments(): void
    {
        foreach (['accounts:create', 'accounts:list', 'accounts:role', 'accounts:disable', 'accounts:password', 'accounts:mfa-reset'] as $name) {
            $command = Artisan::all()[$name];
            $definition = $command->getDefinition();
            $this->assertFalse($definition->hasOption('password'));
            $this->assertFalse($definition->hasArgument('password'));
        }
    }

    private function revocableUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['auth_version' => 7, 'remember_token' => 'old-remember-token'])->save();

        return $user;
    }

    private function assertAudit(string $action, User $user, ?string $secret = null): void
    {
        $audit = AuditLog::query()->sole();
        $this->assertSame($action, $audit->action);
        $this->assertSame('operator', $audit->actor_kind->value);
        $this->assertNull($audit->actor_id);
        $this->assertSame((string) $user->getKey(), $audit->subject_id);
        $this->assertSame(User::class, $audit->subject_type);
        $this->assertNotNull($audit->created_at);
        if ($secret !== null) {
            $this->assertStringNotContainsString($secret, $audit->getRawOriginal('metadata'));
        }
    }

    private function password(): string
    {
        return 'Test-Compte!42'.Str::random(12);
    }
}
