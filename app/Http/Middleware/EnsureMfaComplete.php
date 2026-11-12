<?php

namespace App\Http\Middleware;

use App\Security\AccountAccess;
use App\Security\Onboarding;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMfaComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('filament.admin.auth.logout')) {
            return $next($request);
        }

        $user = $request->user();
        if (filled($user->getAppAuthenticationSecret()) && ! app(AccountAccess::class)->hasVerifiedMfa($user)) {
            abort(403, 'Une nouvelle authentification est nécessaire.');
        }

        $requiredRoute = app(Onboarding::class)->requiredRoute($user);
        if ($requiredRoute !== null) {
            return $request->routeIs($requiredRoute) ? $next($request) : redirect()->route($requiredRoute);
        }

        if ($request->routeIs('filament.admin.setup.*', 'filament.admin.auth.multi-factor-authentication.set-up-required')) {
            return redirect(Filament::getUrl());
        }

        return $next($request);
    }
}
