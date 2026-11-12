<?php

namespace App\Providers\Filament;

use App\Filament\Auth\AccountSecurity;
use App\Filament\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Setup\Complete;
use App\Filament\Setup\Mfa;
use App\Filament\Setup\Password;
use App\Http\Middleware\EnsureAccountSession;
use App\Http\Middleware\EnsureMfaComplete;
use App\Models\User;
use App\Security\AccountAccess;
use App\Security\AppAuthentication;
use App\Security\Onboarding;
use Filament\Actions\Action;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

use function Filament\Support\original_request;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->darkMode(false)
            ->defaultThemeMode(ThemeMode::Light)
            ->login(Login::class)
            ->profile(AccountSecurity::class, isSimple: false)
            ->userMenuItems(['profile' => fn (Action $action): Action => $action->visible(false)])
            ->navigationItems([
                NavigationItem::make(fn (): string => AccountSecurity::getLabel())
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->activeIcon(Heroicon::Cog6Tooth)
                    ->url(fn (): ?string => Filament::getProfileUrl())
                    ->activeWhen(fn (): bool => original_request()->routeIs(AccountSecurity::getRouteName()))
                    ->visible(fn (): bool => ($user = Filament::auth()->user()) instanceof User
                        && app(AccountAccess::class)->allows($user))
                    ->sort(100),
            ])
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()->codeWindow(1)], setUpRequiredAction: Mfa::class, isRequired: true)
            ->multiFactorAuthenticationRoutePrefix('setup')
            ->setUpRequiredMultiFactorAuthenticationRouteSlug('mfa')
            ->authenticatedRoutes(function (): void {
                Route::get('/setup', fn () => redirect(app(Onboarding::class)->nextUrl(Filament::auth()->user())))->name('setup.index');
                Route::get('/setup/password', Password::class)->name('setup.password');
                Route::get('/setup/complete', Complete::class)->name('setup.complete');
            })
            ->brandName('ProxyMed — Dépenses')
            ->brandLogo(fn () => view('components.brand'))
            ->brandLogoHeight('3rem')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->strictAuthorization()
            ->colors([
                'primary' => Color::hex('#1f7c81'),
                'danger' => Color::hex('#c62f46'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                EnsureAccountSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureMfaComplete::class,
            ], isPersistent: true);
    }
}
