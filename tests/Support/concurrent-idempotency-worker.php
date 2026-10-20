<?php

/**
 * One worker for the concurrent idempotency test. Two processes submit the
 * same user/operation/key and the same payload at the same time. The database
 * unique key is the coordination point; exactly one response should perform
 * the write and the other should replay it.
 *
 * Exit codes:
 *   0 = got a 201 (created or replayed)
 *   1 = unexpected failure
 */

use App\Http\Middleware\EnsureIdempotentRequest;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$userId = (int) ($argv[1] ?? 0);
$categoryId = (int) ($argv[2] ?? 0);
$key = (string) ($argv[3] ?? '');
$startAt = (float) ($argv[4] ?? 0);
$holdMs = (int) ($argv[5] ?? 0);

$wait = $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) round($wait * 1_000_000));
}

$startedAt = microtime(true);
$backendPid = null;

try {
    $backendPid = DB::selectOne('select pg_backend_pid() as pid')->pid;
    $user = User::query()->findOrFail($userId);

    $request = Request::create('/api/expenses', 'POST', [
        'amount' => 19.95,
        'category_id' => $categoryId,
        'expense_date' => '2026-10-20',
        'description' => 'Concurrent idempotency probe',
    ]);
    $request->headers->set('Idempotency-Key', $key);
    $request->setUserResolver(fn () => $user);

    $response = app(EnsureIdempotentRequest::class)->handle($request, function (Request $request) use ($holdMs) {
        $expense = Expense::create([
            'user_id' => $request->user()->id,
            'category_id' => $request->input('category_id'),
            'amount' => $request->input('amount'),
            'description' => $request->input('description'),
            'expense_date' => $request->input('expense_date'),
        ]);

        // Keep the first transaction open long enough that the second worker
        // is definitely waiting on the idempotency unique index.
        if ($holdMs > 0) {
            usleep($holdMs * 1000);
        }

        return response()->json([
            'success' => true,
            'data' => $expense->fresh(),
        ], 201);
    });

    echo json_encode([
        'outcome' => 'response',
        'status' => $response->getStatusCode(),
        'replayed' => $response->headers->get('Idempotent-Replay') === 'true',
        'backend_pid' => $backendPid,
        'started_at' => $startedAt,
        'finished_at' => microtime(true),
    ]);

    exit($response->getStatusCode() === 201 ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);

    exit(1);
}
