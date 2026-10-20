<?php

use App\Models\RecurringIncome;
use App\Models\User;
use App\Services\RecurringIncomeService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * This test only runs against PostgreSQL because it relies on real row locks
 * and two independent database connections. SQLite's in-process locking does
 * not exercise the same concurrency semantics, so the test is skipped there.
 */
test('two concurrent receipt attempts create exactly one actual income', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Concurrent receipt locking requires PostgreSQL.');
    }

    $database = DB::connection()->getDatabaseName();

    if (! app()->environment('testing') || ! str_contains($database, 'test')) {
        $this->markTestSkipped('Refusing to run the concurrency test against database '.$database);
    }

    $this->artisan('migrate:fresh', ['--force' => true])->run();

    $workers = [];

    try {
        $user = User::factory()->create();

        $schedule = RecurringIncome::create([
            'user_id' => $user->id,
            'name' => 'Concurrent tutoring',
            'amount' => 500.00,
            'frequency' => 'weekly',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-29',
            'status' => 'active',
        ]);

        app(RecurringIncomeService::class)->generateInitial($schedule);

        $occurrence = $schedule->occurrences()->firstOrFail();
        $startAt = microtime(true) + 2.0;

        $env = [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            'DB_SSLMODE' => (string) config('database.connections.pgsql.sslmode', 'prefer'),
        ];

        $command = [
            PHP_BINARY,
            base_path('tests/Support/concurrent-receipt-worker.php'),
            (string) $schedule->id,
            (string) $occurrence->id,
            (string) $startAt,
            '400',
        ];

        foreach ([1, 2] as $workerNumber) {
            $worker = new Process($command, base_path(), $env, null, 60);
            $worker->start();
            $workers[] = $worker;
        }

        foreach ($workers as $worker) {
            $worker->wait();
        }

        $exitCodes = array_map(fn (Process $worker) => $worker->getExitCode(), $workers);
        $outputs = array_map(function (Process $worker) {
            $decoded = json_decode((string) $worker->getOutput(), true);

            return is_array($decoded) ? $decoded : ['raw' => $worker->getOutput(), 'error' => $worker->getErrorOutput()];
        }, $workers);

        sort($exitCodes);
        expect($exitCodes)->toBe([0, 2], 'Expected exactly one winner and one conflict. '.json_encode($outputs));

        $outcomes = array_column($outputs, 'outcome');
        sort($outcomes);
        expect($outcomes)->toBe(['conflict', 'received']);

        $startedAt = array_map(fn ($output) => (float) ($output['started_at'] ?? 0), $outputs);
        $finishedAt = array_map(fn ($output) => (float) ($output['finished_at'] ?? 0), $outputs);
        $backendPids = array_map(fn ($output) => $output['backend_pid'] ?? null, $outputs);

        expect(abs($startedAt[0] - $startedAt[1]))->toBeLessThan(1.0, 'The two receipt attempts did not start close together.')
            ->and(min($finishedAt) - max($startedAt))->toBeGreaterThan(0.05, 'The two receipt transactions did not overlap in time.')
            ->and($backendPids[0])->not->toBe($backendPids[1], 'Both requests used the same database backend.')
            ->and($backendPids[0])->not->toBeNull();

        $occurrence->refresh();
        expect($occurrence->status)->toBe('received')
            ->and($occurrence->income_id)->not->toBeNull();

        $this->assertDatabaseCount('incomes', 1);
        $this->assertDatabaseHas('incomes', [
            'id' => $occurrence->income_id,
            'user_id' => $user->id,
            'amount' => 500,
        ]);
        $this->assertDatabaseCount('recurring_income_occurrences', 5);
        expect($schedule->occurrences()->where('status', 'received')->count())->toBe(1)
            ->and($schedule->occurrences()->whereNull('income_id')->count())->toBe(4);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }

        $this->artisan('migrate:fresh', ['--force' => true])->run();
    }
});
