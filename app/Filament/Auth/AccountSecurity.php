<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Models\User;
use App\Security\AccountAccess;
use App\Security\AccountSessions;
use App\Services\AuditRecorder;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

class AccountSecurity extends EditProfile
{
    private bool $completedOnboarding = false;

    #[Unexposed]
    public static function getLabel(): string
    {
        return 'Paramètres du compte';
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return $this->requiresPasswordChange() ? 'Choisir votre mot de passe' : static::getLabel();
    }

    #[Unexposed]
    public function getSubheading(): ?string
    {
        return $this->requiresPasswordChange()
            ? 'Dernière étape : remplacez votre mot de passe temporaire pour accéder à l’application.'
            : 'Gérez votre mot de passe et votre authentification à deux facteurs.';
    }

    #[Unexposed]
    public function mount(): void
    {
        abort_unless($this->canChangePassword($this->getUser()), 403);

        parent::mount();
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ])->inlineLabel(false);
    }

    #[Unexposed]
    public function getFormContentComponent(): Component
    {
        return Section::make('Mot de passe')
            ->description('Utilisez au moins 12 caractères. Vous pouvez choisir une phrase de passe avec des espaces.')
            ->schema([parent::getFormContentComponent()]);
    }

    #[Unexposed]
    public function getMultiFactorAuthenticationContentComponent(): ?Component
    {
        if ($this->requiresPasswordChange()) {
            return null;
        }

        return parent::getMultiFactorAuthenticationContentComponent()
            ?->label(null)
            ->heading('Double authentification')
            ->description('Votre application d’authentification protège la connexion à votre compte.')
            ->secondary(false)
            ->compact(false);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->label('Nouveau mot de passe')
            ->required(fn (): bool => $this->requiresPasswordChange())
            ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void {
                if (is_string($value) && Hash::check($value, $this->getUser()->password)) {
                    $fail('Choisissez un mot de passe différent de votre mot de passe actuel.');
                }
            });
    }

    protected function getFormActions(): array
    {
        return $this->requiresPasswordChange() ? [$this->getSaveFormAction()] : parent::getFormActions();
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label($this->requiresPasswordChange() ? 'Terminer et accéder à l’application' : 'Sauvegarder les modifications');
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->completedOnboarding ? Filament::getUrl() : null;
    }

    private function requiresPasswordChange(): bool
    {
        return $this->getUser()->fresh()?->must_change_password === true;
    }

    protected function canChangePassword(User $user): bool
    {
        return app(AccountAccess::class)->allows($user);
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->visible(true)
            ->required(fn (Get $get): bool => $this->requiresPasswordChange() || filled($get('password')));
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return parent::getPasswordConfirmationFormComponent()
            ->visible(true)
            ->required(fn (Get $get): bool => $this->requiresPasswordChange() || filled($get('password')));
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ['password' => null, 'passwordConfirmation' => null, 'currentPassword' => null];
    }

    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        return array_intersect_key($data, ['password' => true]);
    }

    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        abort_unless($record instanceof User && $record->is(Filament::auth()->user()), 403);

        return DB::transaction(function () use ($record, $data): User {
            abort_unless($this->canChangePassword($record), 403);

            if (! filled($data['password'] ?? null)) {
                return $record;
            }

            // The native password component validates and hashes the new password.
            $this->completedOnboarding = $record->fresh()->must_change_password;
            $record->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
            app(AccountSessions::class)->revoke($record);
            app(AuditRecorder::class)->record('account.password_changed', $record, actor: $record);

            $version = (int) $record->auth_version;
            DB::afterCommit(function () use ($record, $version): void {
                $freshUser = $record->fresh();
                if (! $freshUser?->is_active || (int) $freshUser->auth_version !== $version) {
                    return;
                }

                Filament::auth()->setUser($freshUser);
                app(AccountSessions::class)->establish($freshUser, true);
                session()->regenerate();
            });

            return $record;
        });
    }
}
