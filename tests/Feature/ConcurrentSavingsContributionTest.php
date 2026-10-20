<?php

use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * PostgreSQL-only: two genuine goals contributions must both be recorded and
 * the target/status calculation must be serialized by the goal row lock.
 */
test('two concurrent contributions are both recorded without losing an update', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Concurrent contribution locking requires PostgreSQL.');
    }

    $database = DB::connection()->getDatabaseName();

    if (! app()->environment('testing') || ! str_contains($database, 'test')) {
        $this->markTestSkipped('Refusing to run the concurrency test against database '.$database);
    }

    $this->artisan('migrate:fresh', ['--force' => true])->run();

    $workers = [];

    try {
        $user = User::factory()->create();
        $goal = SavingsGoal::create([
            'user_id' => $user->id,
            'name' => 'Concurrent savings goal',
            'target_amount' => 100,
            'status' => SavingsGoal::STATUS_ACTIVE,
        ]);
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
            base_path('tests/Support/concurrent-savings-contribution-worker.php'),
            (string) $goal->id,
            (string) $startAt,
            '500',
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

        expect($exitCodes)->toBe([0, 0], 'Both valid contributions should succeed. '.json_encode($outputs));

        $startedAt = array_map(fn ($output) => (float) ($output['started_at'] ?? 0), $outputs);
        $finishedAt = array_map(fn ($output) => (float) ($output['finished_at'] ?? 0), $outputs);
        $backendPids = array_map(fn ($output) => $output['backend_pid'] ?? null, $outputs);
        $counts = array_map(fn ($output) => $output['contribution_count'] ?? null, $outputs);
        sort($counts);

        expect(abs($startedAt[0] - $startedAt[1]))->toBeLessThan(1.0, 'The two contributions did not start close together.')
            ->and(min($finishedAt) - max($startedAt))->toBeGreaterThan(0.2, 'The two transactions did not overlap in time.')
            ->and($backendPids[0])->not->toBe($backendPids[1], 'Both requests used the same database backend.')
            ->and($backendPids[0])->not->toBeNull()
            ->and($counts)->toBe([1, 2], 'The row lock did not serialize the contribution calculations.');

        $goal->refresh();
        expect((string) $goal->fresh()->target_amount)->toBe('100.00')
            ->and($goal->fresh()->status)->toBe(SavingsGoal::STATUS_ACTIVE);

        $this->assertDatabaseCount('savings_goal_contributions', 2);
        $this->assertDatabaseHas('savings_goal_contributions', [
            'savings_goal_id' => $goal->id,
            'type' => 'contribution',
            'amount' => 30,
        ]);

        $saved = DB::table('savings_goal_contributions')
            ->where('savings_goal_id', $goal->id)
            ->sum('amount');
        expect((float) $saved)->toBe(60.0);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }

        $this->artisan('migrate:fresh', ['--force' => true])->run();
    }
});
