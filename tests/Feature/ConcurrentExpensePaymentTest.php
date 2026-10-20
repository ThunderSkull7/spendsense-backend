<?php

use App\Models\Category;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\RecurringExpenseService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * PostgreSQL-only: prove that two independent payment requests cannot both
 * create an expense for the same occurrence. SQLite's in-process locking does
 * not exercise the same row-lock semantics.
 */
test('two concurrent payment attempts create exactly one actual expense', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Concurrent payment locking requires PostgreSQL.');
    }

    $database = DB::connection()->getDatabaseName();

    if (! app()->environment('testing') || ! str_contains($database, 'test')) {
        $this->markTestSkipped('Refusing to run the concurrency test against database '.$database);
    }

    $this->artisan('migrate:fresh', ['--force' => true])->run();

    $workers = [];

    try {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Concurrent bills', 'type' => 'expense']);

        $schedule = RecurringExpense::create([
            'user_id' => $user->id,
            'description' => 'Concurrent bill',
            'amount' => 500.00,
            'category_id' => $category->id,
            'frequency' => 'weekly',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-29',
            'status' => 'active',
            'reminder_days_before' => 3,
        ]);

        app(RecurringExpenseService::class)->generateInitial($schedule);
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
            base_path('tests/Support/concurrent-expense-payment-worker.php'),
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
        expect($outcomes)->toBe(['conflict', 'paid']);

        $startedAt = array_map(fn ($output) => (float) ($output['started_at'] ?? 0), $outputs);
        $finishedAt = array_map(fn ($output) => (float) ($output['finished_at'] ?? 0), $outputs);
        $backendPids = array_map(fn ($output) => $output['backend_pid'] ?? null, $outputs);

        expect(abs($startedAt[0] - $startedAt[1]))->toBeLessThan(1.0, 'The two payment attempts did not start close together.')
            ->and(min($finishedAt) - max($startedAt))->toBeGreaterThan(0.05, 'The two payment transactions did not overlap in time.')
            ->and($backendPids[0])->not->toBe($backendPids[1], 'Both requests used the same database backend.')
            ->and($backendPids[0])->not->toBeNull();

        $occurrence->refresh();
        expect($occurrence->status)->toBe('paid')
            ->and($occurrence->expense_id)->not->toBeNull();

        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseHas('expenses', [
            'id' => $occurrence->expense_id,
            'user_id' => $user->id,
            'amount' => 500,
        ]);
        expect($schedule->occurrences()->where('status', 'paid')->count())->toBe(1)
            ->and($schedule->occurrences()->whereNull('expense_id')->count())->toBe(4);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }

        $this->artisan('migrate:fresh', ['--force' => true])->run();
    }
});
