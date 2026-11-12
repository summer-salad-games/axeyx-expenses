<?php

namespace App\Security;

use App\Models\User;
use App\Services\AuditRecorder;
use Filament\Auth\MultiFactor\App\AppAuthentication as BaseAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class AppAuthentication extends BaseAppAuthentication
{
    private ?int $enrollingUserId = null;

    private ?string $verifiedEnrollmentSecret = null;

    private ?string $verifiedEnrollmentCode = null;

    public function verifyCode(#[SensitiveParameter] string $code, #[SensitiveParameter] ?string $secret = null, bool $shouldPreventCodeReuse = false): bool
    {
        $valid = parent::verifyCode($code, $secret, $shouldPreventCodeReuse);
        if ($valid && ! $shouldPreventCodeReuse && $secret !== null) {
            $this->verifiedEnrollmentSecret = $secret;
            $this->verifiedEnrollmentCode = $code;
        }

        return $valid;
    }

    public function getRecoveryCodes(HasAppAuthenticationRecovery $user): array
    {
        return $user->getAppAuthenticationRecoveryCodes() ?? [];
    }

    public function getActions(): array
    {
        return array_values(array_filter(parent::getActions(), fn ($action): bool => $action->getName() !== 'disableAppAuthentication'));
    }

    public function getChallengeFormComponents(Authenticatable $user): array
    {
        $components = parent::getChallengeFormComponents($user);
        foreach ($components as $component) {
            if ($component instanceof OneTimeCodeInput) {
                $component->visible(fn (Get $get): bool => ! $get('useRecoveryCode'));
            }
            if ($component instanceof TextInput && $component->getName() === 'recoveryCode') {
                // Submit the code with the form: a blur request can swallow the submit click.
                $component->live(condition: false)->required();
            }
        }

        return $components;
    }

    public function saveSecret(HasAppAuthentication $user, #[SensitiveParameter] ?string $secret): void
    {
        abort_unless($user instanceof User && filled($secret), 403);
        $verifiedSecret = $this->verifiedEnrollmentSecret;
        $verifiedCode = $this->verifiedEnrollmentCode;
        $this->verifiedEnrollmentSecret = null;
        $this->verifiedEnrollmentCode = null;
        DB::transaction(function () use ($user, $secret, $verifiedSecret, $verifiedCode): void {
            $fresh = $user->fresh();
            abort_unless($fresh && app(AccountSessions::class)->valid($fresh) && ! filled($fresh->getAppAuthenticationSecret()), 403);
            abort_unless($verifiedSecret === $secret
                && $verifiedCode !== null
                && parent::verifyCode($verifiedCode, $secret, shouldPreventCodeReuse: true), 403);
            parent::saveSecret($fresh, $secret);
            app(AccountSessions::class)->revoke($fresh);
            app(AuditRecorder::class)->record('account.mfa_enrolled', $fresh, [], $fresh);
            $this->enrollingUserId = $fresh->getKey();
            $user->refresh();
            $this->preserveCurrentSessionAfterCommit($fresh);
        });
    }

    public function saveRecoveryCodes(HasAppAuthenticationRecovery $user, #[SensitiveParameter] ?array $codes): void
    {
        abort_unless($user instanceof User && is_array($codes), 403);
        DB::transaction(function () use ($user, $codes): void {
            $fresh = $user->fresh();
            $enrolling = $this->enrollingUserId === $user->getKey();
            abort_unless($fresh && ($enrolling || app(AccountAccess::class)->allows($fresh)), 403);
            parent::saveRecoveryCodes($fresh, $codes);
            if (! $enrolling) {
                app(AccountSessions::class)->revoke($fresh);
                app(AuditRecorder::class)->record('account.recovery_codes_regenerated', $fresh, [], $fresh);
                $this->preserveCurrentSessionAfterCommit($fresh);
            }
            $user->refresh();
            $this->enrollingUserId = null;
        });
    }

    private function preserveCurrentSessionAfterCommit(User $user): void
    {
        $expectedVersion = $user->auth_version;
        DB::afterCommit(function () use ($user, $expectedVersion): void {
            $fresh = $user->fresh();
            if ($fresh && $fresh->is_active && $fresh->auth_version === $expectedVersion) {
                app(AccountSessions::class)->establish($fresh, true);
                session()->regenerate();
            }
        });
    }
}
