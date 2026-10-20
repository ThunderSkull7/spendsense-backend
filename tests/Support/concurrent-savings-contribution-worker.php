<?php

use App\Models\SavingsGoal;
use App\Models\SavingsGoalContribution;
use App\Services\SavingsGoalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$goalId = (int) ($argv[1] ?? 0);
$startAt = (float) ($argv[2] ?? 0);
$holdMilliseconds = (int) ($argv[3] ?? 400);

// Hold the row lock briefly so the two legitimate contributions overlap.
SavingsGoalContribution::creating(function () use ($holdMilliseconds) {
    usleep($holdMilliseconds * 1000);
});

$wait = (int) (($startAt - microtime(true)) * 1_000_000);
if ($wait > 0) {
    usleep($wait);
}

$startedAt = microtime(true);
$goal = SavingsGoal::query()->findOrFail($goalId);

try {
    $result = app(SavingsGoalService::class)->addEntry(
        $goal,
        ['amount' => '30.00', 'note' => 'Concurrent contribution'],
        SavingsGoalContribution::TYPE_CONTRIBUTION
    );

    echo json_encode([
        'outcome' => 'recorded',
        'started_at' => $startedAt,
        'finished_at' => microtime(true),
        'backend_pid' => DB::selectOne('select pg_backend_pid() as pid')->pid,
        'saved_amount' => $result['saved_amount'],
        'contribution_count' => $result['contribution_count'],
    ]);
} catch (Throwable $exception) {
    echo json_encode([
        'outcome' => 'error',
        'error' => $exception->getMessage(),
        'started_at' => $startedAt,
        'finished_at' => microtime(true),
        'backend_pid' => DB::selectOne('select pg_backend_pid() as pid')->pid,
    ]);
    exit(1);
}
