<?php

namespace App\Http\Middleware;

use App\Models\Expense;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAttachmentUploadAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        Gate::authorize('create', Expense::class);
        if ($request->isMethod('POST')) {
            $files = $request->file('files', []);
            abort_unless(is_array($files) && count($files) === 1, 422);
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "sandbox; default-src 'none'");

        return $response;
    }
}
