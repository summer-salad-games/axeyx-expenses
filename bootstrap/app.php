<?php

use App\Http\Middleware\EnsureAccountSession;
use App\Http\Middleware\NormalizeLivewirePageRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([SecurityHeaders::class, NormalizeLivewirePageRequests::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->web(append: [EnsureAccountSession::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (MethodNotAllowedHttpException $exception, Request $request): void {
            if (app()->environment('local')) {
                Log::warning('http.method_not_allowed', [
                    'method' => $request->method(),
                    'path' => $request->getPathInfo(),
                    'allowed_methods' => $exception->getHeaders()['Allow'] ?? null,
                    'routing_message' => $exception->getMessage(),
                    'livewire_request' => $request->hasHeader('X-Livewire'),
                ]);
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// Test restores must never rewrite the owner’s environment file.
$runner = getenv('AXEYX_TEST_RUNNER');
if (in_array($runner, ['testing', 'e2e'], true)) {
    $app->useEnvironmentPath($app->basePath('.data/'.$runner.'/environment'));
}

return $app;
