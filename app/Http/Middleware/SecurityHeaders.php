<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        if ($request->isMethod('POST') && $request->routeIs('filament.admin.auth.logout') && $response->isRedirection()) {
            $response->headers->set('Clear-Site-Data', '"cache", "storage"');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (! $response->headers->has('Content-Security-Policy')) {
            // Filament/Alpine use inline styles/scripts and evaluated expressions. Keep this
            // compatible baseline explicit rather than claiming a nonce-based strict CSP.
            $scripts = "'self' 'unsafe-inline' 'unsafe-eval'";
            $connections = "'self'";
            if (app()->environment('local')) {
                $scripts .= ' http://127.0.0.1:5173 http://localhost:5173';
                $connections .= ' http://127.0.0.1:5173 http://localhost:5173 ws://127.0.0.1:5173 ws://localhost:5173';
            }
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src ".$scripts
                ."; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src ".$connections
                ."; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        }
        // Enable only after owner-run HTTPS validation; never broaden to other subdomains.
        if (config('app.hsts_enabled') && app()->environment('production') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
