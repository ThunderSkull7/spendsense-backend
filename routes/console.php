<?php

use App\Models\IdempotencyRecord;
use App\Services\BillReminderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Idempotency responses contain financial data. Keep them only for the retry
// window, then remove them. A scheduler is still required in production.
Schedule::command('model:prune', ['--model' => [IdempotencyRecord::class]])
    ->daily()
    ->at('03:15');

// In-app bill reminders are also reconciled on read, but a daily scheduler run
// keeps unread reminders current. This requires a running Laravel scheduler on
// the hosting platform; it is not verified by local tests alone.
Artisan::command('recurring-expenses:sync-reminders', function () {
    $inserted = app(BillReminderService::class)->syncAll();
    $this->info("Created {$inserted} new bill reminder(s).");
})->purpose('Reconcile in-app reminders for upcoming and overdue recurring expenses');

Schedule::command('recurring-expenses:sync-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping();
