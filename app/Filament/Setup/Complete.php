<?php

namespace App\Filament\Setup;

use App\Models\User;
use App\Security\AccountAccess;
use App\Services\AuditRecorder;
use Filament\Facades\Filament;
use Filament\Pages\SimplePage;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\DB;

class Complete extends SimplePage
{
    protected static string $layout = 'setup.layout';

    protected string $view = 'setup.complete';

    #[Unexposed]
    public function mount(): void
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User && app(AccountAccess::class)->hasVerifiedMfa($user) && ! $user->fresh()->must_change_password, 403);
        if ($user->fresh()->onboarding_completed_at !== null) {
            $this->redirect(url('/'));
        }
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return 'Tout est prêt !';
    }

    #[Unexposed]
    public function getSubheading(): string
    {
        return 'Votre compte est sécurisé. Vous pouvez maintenant accéder au registre des dépenses.';
    }

    #[Unexposed]
    public function getSetupStep(): int
    {
        return 3;
    }

    #[Exposed]
    public function finish(): void
    {
        DB::transaction(function (): void {
            $actor = Filament::auth()->user();
            abort_unless($actor instanceof User, 403);
            $user = $actor->fresh();
            abort_unless(app(AccountAccess::class)->hasVerifiedMfa($user) && ! $user->must_change_password, 403);
            if ($user->onboarding_completed_at === null) {
                $user->forceFill(['onboarding_completed_at' => now()])->save();
                app(AuditRecorder::class)->record('account.onboarding_completed', $user, actor: $user);
            }
        }, 3);
        session()->forget('url.intended');
        $this->redirect(url('/'));
    }
}
