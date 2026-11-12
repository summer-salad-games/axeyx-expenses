<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Security\AccountSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAccountSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $fresh = $user->fresh();
        if (! $fresh || ! app(AccountSessions::class)->valid($fresh)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login');
        }
        Auth::guard('web')->setUser($fresh);

        return $next($request);
    }
}
