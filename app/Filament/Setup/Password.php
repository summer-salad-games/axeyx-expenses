<?php

namespace App\Filament\Setup;

use App\Filament\Auth\AccountSecurity;
use App\Models\User;
use App\Security\AccountAccess;
use Filament\Actions\Action;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Support\Components\Attributes\Unexposed;

class Password extends AccountSecurity
{
    #[Unexposed]
    public function getLayout(): string
    {
        return 'setup.layout';
    }

    #[Unexposed]
    public function getView(): string
    {
        return 'setup.password';
    }

    #[Unexposed]
    public function getSetupStep(): int
    {
        return 2;
    }

    #[Unexposed]
    public function getSubheading(): ?string
    {
        return 'Remplacez le mot de passe temporaire par un mot de passe personnel.';
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->helperText('Au moins 12 caractères. Les espaces et les accents sont acceptés.');
    }

    protected function canChangePassword(User $user): bool
    {
        return app(AccountAccess::class)->hasVerifiedMfa($user) && $user->fresh()?->must_change_password === true;
    }

    #[Unexposed]
    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
            ->footer([Actions::make([$this->getSaveFormAction()])]);
    }

    #[Unexposed]
    public function getMultiFactorAuthenticationContentComponent(): ?Component
    {
        return null;
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Continuer');
    }

    protected function getRedirectUrl(): ?string
    {
        session()->forget('url.intended');

        return route('filament.admin.setup.complete');
    }
}
