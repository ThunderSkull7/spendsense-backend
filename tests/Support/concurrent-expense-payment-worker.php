<?php

/**
 * One concurrent bill-payment worker. Launched by
 * tests/Feature/ConcurrentExpensePaymentTest.php in two independent PHP
 * processes so the database row lock, not in-process locking, is exercised.
 *
 * Exit codes:
 *   0 = occurrence paid
 *   2 = occurrence already paid (expected loser of the race)
 *   1 = unexpected failure
 */

use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Services\RecurringExpenseService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$scheduleId = (int) ($argv[1] ?? 0);
$occurrenceId = (int) ($argv[2] ?? 0);
$startAt = (float) ($argv[3] ?? 0);
$holdLockMs = (int) ($argv[4] ?? 0);

$wait = $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) round($wait * 1_000_000));
}

// Hold the schedule lock long enough that the second worker is guaranteed to
// be inside pay() while the first transaction is still open. This is a
// test-only delay; production relies on the row lock itself.
if ($holdLockMs > 0) {
    Expense::creating(function () use ($holdLockMs) {
        usleep($holdLockMs * 1000);
    });
}

$startedAt = microtime(true);

try {
    $backendPid = DB::selectOne('select pg_backend_pid() as pid')->pid;

    $schedule = RecurringExpense::query()->findOrFail($scheduleId);
    $occurrence = $schedule->occurrences()->whereKey($occurrenceId)->firstOrFail();
    $paid = app(RecurringExpenseService::class)->pay($schedule, $occurrence, $schedule->user_id);

    echo json_encode([
        'outcome' => 'paid',
        'backend_pid' => $backendPid,
        'occurrence_id' => $paid->id,
        'expense_id' => $paid->expense_id,
        'started_at' => $startedAt,
        'finished_at' => microtime(true),
    ]);

    exit(0);
} catch (ConflictHttpException $exception) {
    echo json_encode([
        'outcome' => 'conflict',
        'backend_pid' => $backendPid ?? null,
        'message' => $exception->getMessage(),
        'started_at' => $startedAt,
        'finished_at' => microtime(true),
    ]);

    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);

    exit(1);
}
