<?php

/**
 * One concurrent receipt worker. This file is launched by
 * tests/Feature/ConcurrentReceiptTest.php in two separate PHP processes. A
 * shared start timestamp acts as a barrier so both processes reach the
 * service call at the same time.
 *
 * Exit codes:
 *   0 = occurrence received
 *   2 = occurrence already received (expected loser of the race)
 *   1 = unexpected failure
 */

use App\Models\Income;
use App\Models\RecurringIncome;
use App\Services\RecurringIncomeService;
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
// be inside receive() while the first transaction is still open. This is a
// test-only delay: the lock itself is what production relies on.
if ($holdLockMs > 0) {
    Income::creating(function () use ($holdLockMs) {
        usleep($holdLockMs * 1000);
    });
}

$startedAt = microtime(true);

try {
    $backendPid = DB::selectOne('select pg_backend_pid() as pid')->pid;

    $schedule = RecurringIncome::query()->findOrFail($scheduleId);
    $occurrence = app(RecurringIncomeService::class)->receive($schedule, $occurrenceId);

    echo json_encode([
        'outcome' => 'received',
        'backend_pid' => $backendPid,
        'occurrence_id' => $occurrence->id,
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
