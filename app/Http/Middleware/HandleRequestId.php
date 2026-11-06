<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class HandleRequestId
{
    /**
     * Handle an incoming request.
     * Generates or propagates a privacy-safe correlation ID (X-Request-Id)
     * and logs structured diagnostics without leaking sensitive data.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incomingId = $request->header('X-Request-Id');
        $requestId = ($incomingId && preg_match('/^[a-zA-Z0-9_\-]{8,64}$/', $incomingId))
            ? $incomingId
            : 'req_' . Str::lower(Str::random(16));

        $request->headers->set('X-Request-Id', $requestId);
        $startTime = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $response->headers->set('X-Request-Id', $requestId);

        // Structured, privacy-safe diagnostic logging (OBS-01).
        // Never log passwords, tokens, cookies, authorization headers, or financial records.
        if (config('app.debug') || $response->getStatusCode() >= 400) {
            $userId = auth()->id() ?? 'guest';
            Log::info(sprintf(
                '[%s] %s %s -> %d (%dms) [user: %s]',
                $requestId,
                $request->method(),
                $request->path(),
                $response->getStatusCode(),
                $durationMs,
                $userId
            ));
        }

        return $response;
    }
}

