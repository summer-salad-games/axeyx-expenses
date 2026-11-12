<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class NormalizeLivewirePageRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD'], true)) {
            // Fetch follows POST redirects with this header intact. A page GET has no component snapshot.
            $request->headers->remove('X-Livewire');
        }

        return $next($request);
    }
}
