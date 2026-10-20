<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyRecord;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotentRequest
{
    /**
     * Replay a completed response for the same user, operation and key. The
     * key is optional so existing clients keep their current API contract.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Idempotency-Key');

        if (is_array($header)) {
            $header = $header[0] ?? null;
        }

        $key = trim((string) $header);

        if ($key === '') {
            return $next($request);
        }

        if (strlen($key) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            return response()->json([
                'success' => false,
                'message' => 'The Idempotency-Key header must be 1-128 characters and contain only letters, numbers, dot, underscore, colon or hyphen.',
                'errors' => [
                    'idempotency_key' => ['Invalid Idempotency-Key header.'],
                ],
            ], 422);
        }

        $userId = $request->user()?->id;

        if ($userId === null) {
            // Authentication middleware owns the 401 response.
            return $next($request);
        }

        $operation = substr($request->method().' '.$request->path(), 0, 190);
        $requestHash = $this->requestHash($request);

        $existing = $this->findRecord($userId, $operation, $key);

        if ($existing !== null) {
            if ($existing->expires_at->isPast()) {
                $existing->delete();
            } else {
                return $this->replayOrConflict($existing, $requestHash);
            }
        }

        try {
            return DB::transaction(function () use ($request, $next, $userId, $operation, $key, $requestHash) {
                $record = IdempotencyRecord::create([
                    'user_id' => $userId,
                    'operation' => $operation,
                    'idempotency_key' => $key,
                    'request_hash' => $requestHash,
                    'expires_at' => now()->addDay(),
                ]);

                $response = $next($request);

                // Only successful responses are replayable. Validation and
                // authorization failures leave no idempotency record, so the
                // client may correct the request and retry with the same key.
                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    $record->forceFill([
                        'response_status' => $response->getStatusCode(),
                        'response_body' => $response->getContent(),
                    ])->save();
                } else {
                    $record->delete();
                }

                return $response;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request with the same key won the unique insert.
            // Re-read its committed record and replay or reject deterministically.
            $existing = $this->findRecord($userId, $operation, $key);

            if ($existing !== null) {
                return $this->replayOrConflict($existing, $requestHash);
            }

            throw $exception;
        }
    }

    private function findRecord(int $userId, string $operation, string $key): ?IdempotencyRecord
    {
        return IdempotencyRecord::query()
            ->where('user_id', $userId)
            ->where('operation', $operation)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function replayOrConflict(IdempotencyRecord $record, string $requestHash): Response
    {
        if (! hash_equals($record->request_hash, $requestHash)) {
            return response()->json([
                'success' => false,
                'message' => 'This Idempotency-Key was already used for a different request.',
            ], 409);
        }

        if ($record->response_status === null) {
            return response()->json([
                'success' => false,
                'message' => 'A request with this Idempotency-Key is still being processed. Retry shortly.',
            ], 409);
        }

        $response = response($record->response_body ?? '', $record->response_status);

        if ($record->response_body !== null && $record->response_body !== '') {
            $response->header('Content-Type', 'application/json');
        }

        $response->header('Idempotent-Replay', 'true');

        return $response;
    }

    private function requestHash(Request $request): string
    {
        $payload = $request->all();
        $this->sortRecursive($payload);

        return hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: ''
        );
    }

    private function sortRecursive(array &$payload): void
    {
        ksort($payload);

        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }
    }
}
