<?php

use App\Http\Middleware\EnsureIdempotentRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable Sanctum's stateful, cookie-based SPA authentication for the
        // "api" middleware group. For requests whose Origin/Referer matches a
        // configured stateful domain, this starts the session and applies CSRF
        // protection, so auth cookies persist across separate requests.
        $middleware->statefulApi();

        $middleware->alias([
            'idempotent' => EnsureIdempotentRequest::class,
        ]);

        // This backend is a JSON API. Never redirect guests to a web "login"
        // route (which does not exist). Unauthenticated requests are rendered
        // as JSON 401 responses by the exception configuration below.
        $middleware->redirectGuestsTo(fn () => null);

        // Render (and comparable PaaS platforms) terminate TLS at their edge
        // and forward X-Forwarded-* headers. Trust the platform proxy so the
        // client IP (used by the login/register throttles) and the original
        // request scheme are detected correctly. Trusting all proxies is safe
        // here because the container is only reachable through Render's edge.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always render JSON for API traffic, even when the client omits the
        // "Accept: application/json" header. This guarantees 401/422/500
        // responses stay JSON and no "Route [login] not defined" error occurs.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*')
                || $request->is('user')
                || $request->expectsJson()
        );
    })->create();
