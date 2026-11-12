<?php

namespace App\Filament\Setup;

use App\Models\AuditLog;
use App\Models\User;
use App\Security\AccountAccess;
use App\Security\AccountSessions;
use App\Security\Onboarding;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class Mfa extends SetUpRequiredMultiFactorAuthentication
{
    protected static string $layout = 'setup.layout';

    protected string $view = 'setup.mfa';

    #[Unexposed]
    public function boot(): void
    {
        // The mounted action is inline, so updates must render the page instead of a modal partial.
        $this->forceRender();
    }

    #[Unexposed]
    public function mount(): void
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User && app(AccountSessions::class)->valid($user->fresh()), 403);
        if ($this->isEnabled()) {
            abort_unless(app(AccountAccess::class)->hasVerifiedMfa($user), 403);
            $this->redirect(app(Onboarding::class)->nextUrl(Filament::auth()->user()->fresh()));

            return;
        }
        $this->mountAction('setUpAppAuthentication', context: ['schemaComponent' => 'content.app']);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return 'Sécurisez votre compte';
    }

    #[Unexposed]
    public function getHeading(): string
    {
        return $this->getTitle();
    }

    #[Unexposed]
    public function getSubheading(): string
    {
        return 'Configurez votre application d’authentification, puis conservez vos codes de récupération.';
    }

    #[Unexposed]
    public function getSetupStep(): int
    {
        return 1;
    }

    #[Unexposed]
    public function getSetupSchema(): ?Schema
    {
        return $this->getMountedActionSchema();
    }

    #[Unexposed]
    public function getMultiFactorAuthenticationContentComponent(): Component
    {
        $provider = Filament::getMultiFactorAuthenticationProviders()['app'];
        $action = SetUpAppAuthenticationAction::make($provider)->modalCancelAction(false);

        if (! $this->requiresPasswordConfirmation()) {
            $action->modifyWizardUsing(function (Wizard $wizard): Wizard {
                $step = $wizard->getDefaultChildComponents()[0];
                $step->schema(array_values(array_filter(
                    $step->getDefaultChildComponents(),
                    fn (Component $component): bool => ! ($component instanceof TextInput && $component->getName() === 'password'),
                )))->beforeValidation(fn (Step $component) => $this->rateLimitEnrollment(
                    $component->getChildSchema()->getComponent('code')->getStatePath(),
                ));

                return $wizard->hiddenHeader();
            })->beforeFormValidated(fn () => $this->rateLimitEnrollment(
                $this->getSetupSchema()->getComponent('code', withHidden: true)->getStatePath(),
            ));
        }

        return Group::make([
            Actions::make([$action]),
        ])->statePath('app');
    }

    private function requiresPasswordConfirmation(): bool
    {
        $user = Filament::auth()->user()->fresh();

        return ! $user->must_change_password || AuditLog::query()
            ->where('subject_type', User::class)
            ->where('subject_id', (string) $user->getKey())
            ->whereIn('action', ['account.mfa_enrolled', 'account.mfa_reset'])
            ->exists();
    }

    private function rateLimitEnrollment(string $codeStatePath): void
    {
        // Keep the native setup attempt budget when the initial password field is omitted.
        $key = 'filament:auth:set_up_app_authentication:rate_limit:'.Filament::getUserScopedAuthIdentifier();
        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                $codeStatePath => __('filament-panels::auth/multi-factor/app/actions/set-up.modal.form.code.messages.rate_limited'),
            ]);
        }

        RateLimiter::hit($key);
    }

    protected function afterActionCalled(Action $action): void
    {
        if ($action->getName() === 'setUpAppAuthentication') {
            session()->forget('url.intended');
            $this->redirect(app(Onboarding::class)->nextUrl(Filament::auth()->user()->fresh()));
        }
    }
}
