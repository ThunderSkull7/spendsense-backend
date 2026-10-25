<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use App\Models\User;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\BudgetAlertController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AnalyticsInsightsController;
use App\Http\Controllers\BillReminderController;
use App\Http\Controllers\CashFlowForecastController;
use App\Http\Controllers\DashboardPreferenceController;
use App\Http\Controllers\FullDataExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\NetWorthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RecurringExpenseController;
use App\Http\Controllers\RecurringIncomeController;
use App\Http\Controllers\SavingsGoalController;
use App\Http\Controllers\SpendingInsightsController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TransactionSearchController;

/*
|--------------------------------------------------------------------------
| API Routes: Authentication
|--------------------------------------------------------------------------
*/

// Get the CSRF cookie for Sanctum cookie-based auth.
// Laravel's CSRF middleware (applied by Sanctum to stateful requests) issues
// the real XSRF-TOKEN cookie; this endpoint simply triggers that response.
Route::get('/sanctum/csrf-cookie', function () {
    return response()->noContent();
});

// Login endpoint
Route::post('/login', function (Request $request) {
    // Normalize email casing so lookups match however the user typed the
    // address (PostgreSQL string comparisons are case-sensitive; MySQL was not).
    $email = $request->input('email');

    if (is_string($email)) {
        $request->merge(['email' => Str::lower($email)]);
    }

    $request->validate([
        'email'    => 'required|email',
        'password' => 'required',
    ]);

    if (! auth('web')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    // Regenerate the session id to protect against session fixation.
    if ($request->hasSession()) {
        $request->session()->regenerate();
    }

    return response()->json(['user' => auth('web')->user()]);
})->middleware('throttle:6,1');

// Register endpoint
Route::post('/register', function (Request $request) {
    // Normalize email casing before validation so the uniqueness check and
    // the stored value stay consistent (PostgreSQL's unique index is
    // case-sensitive).
    $email = $request->input('email');

    if (is_string($email)) {
        $request->merge(['email' => Str::lower($email)]);
    }

    $request->validate([
        'name'     => 'required|string|max:255',
        'email'    => 'required|string|email|max:255|unique:users',
        'password' => 'required|string|min:8|confirmed',
    ]);

    $user = User::create([
        'name'     => $request->name,
        'email'    => $request->email,
        'password' => $request->password, // hashed by the model's "hashed" cast
    ]);

    // Log the user in immediately so the session is established.
    auth('web')->login($user);

    if ($request->hasSession()) {
        $request->session()->regenerate();
    }

    return response()->json([
        'user' => auth('web')->user(),
        'message' => 'Registration successful. Welcome!',
    ], 201);
})->middleware('throttle:6,1');

// Logout endpoint
Route::post('/logout', function (Request $request) {
    auth('web')->logout();

    if ($request->hasSession()) {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    return response()->json(['status' => 'logged_out']);
});

Route::middleware('auth:sanctum')->group(function () {
    // Current user profile. The legacy /user route remains in routes/web.php
    // for existing callers; the frontend uses /api/user so session hydration
    // and profile updates share the same API client and CSRF handling.
    Route::get('/user', [ProfileController::class, 'show']);
    Route::put('/user', [ProfileController::class, 'update']);

    // Numeric-only identifiers keep malformed IDs on the normal 404 path.
    Route::pattern('expense', '[0-9]+');
    Route::pattern('income', '[0-9]+');
    Route::pattern('category', '[0-9]+');
    Route::pattern('budget', '[0-9]+');
    Route::pattern('recurringIncome', '[0-9]+');
    Route::pattern('recurringExpense', '[0-9]+');
    Route::pattern('reminder', '[0-9]+');
    Route::pattern('occurrence', '[0-9]+');
    Route::pattern('netWorthEntry', '[0-9]+');
    Route::pattern('subscription', '[0-9]+');

    // Expenses
    Route::post('/expenses',             [ExpenseController::class, 'store'])->middleware('idempotent');
    Route::get('/expenses',              [ExpenseController::class, 'index']);
    Route::get('/expenses/trash',        [ExpenseController::class, 'trash']);
    Route::post('/expenses/{expense}/restore', [ExpenseController::class, 'restore']);
    Route::get('/expenses/{expense}',    [ExpenseController::class, 'show']);
    Route::put('/expenses/{expense}',    [ExpenseController::class, 'update']);
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);

    // Incomes
    Route::post('/incomes',             [IncomeController::class, 'store'])->middleware('idempotent');
    Route::get('/incomes',              [IncomeController::class, 'index']);
    Route::get('/incomes/trash',        [IncomeController::class, 'trash']);
    Route::post('/incomes/{income}/restore', [IncomeController::class, 'restore']);
    Route::get('/incomes/{income}',     [IncomeController::class, 'show']);
    Route::put('/incomes/{income}',     [IncomeController::class, 'update']);
    Route::delete('/incomes/{income}',  [IncomeController::class, 'destroy']);

    // Categories
    Route::post('/categories',              [CategoryController::class, 'store']);
    Route::get('/categories',               [CategoryController::class, 'index']);
    Route::get('/categories/{category}',    [CategoryController::class, 'show']);
    Route::put('/categories/{category}',    [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

    // Budgets
    Route::post('/budgets',             [BudgetController::class, 'store'])->middleware('idempotent');
    Route::get('/budgets',              [BudgetController::class, 'index']);
    Route::get('/budgets/{budget}',     [BudgetController::class, 'show']);
    Route::put('/budgets/{budget}',     [BudgetController::class, 'update']);
    Route::delete('/budgets/{budget}',  [BudgetController::class, 'destroy']);

    // Savings goals: expectations only; contributions never post to the
    // expense or income ledgers.
    Route::get('/savings-goals',        [SavingsGoalController::class, 'index']);
    Route::post('/savings-goals',       [SavingsGoalController::class, 'store'])->middleware('idempotent');
    Route::get('/savings-goals/{savingsGoal}',    [SavingsGoalController::class, 'show']);
    Route::put('/savings-goals/{savingsGoal}',    [SavingsGoalController::class, 'update']);
    Route::post('/savings-goals/{savingsGoal}/archive',  [SavingsGoalController::class, 'archive']);
    Route::post('/savings-goals/{savingsGoal}/restore',  [SavingsGoalController::class, 'restore']);
    Route::post('/savings-goals/{savingsGoal}/contributions', [SavingsGoalController::class, 'storeContribution'])->middleware('idempotent');
    Route::post('/savings-goals/{savingsGoal}/corrections',   [SavingsGoalController::class, 'correctContribution'])->middleware('idempotent');

    // Persisted in-app budget threshold alerts for a calendar month.
    Route::get('/budget-alerts', [BudgetAlertController::class, 'index']);
    Route::post('/budget-alerts/read-all', [BudgetAlertController::class, 'readAll']);
    Route::post('/budget-alerts/{budgetAlert}/read', [BudgetAlertController::class, 'read']);

    // Recurring income schedules and planned occurrences
    Route::post('/recurring-incomes/preview', [RecurringIncomeController::class, 'preview']);
    Route::get('/recurring-incomes',          [RecurringIncomeController::class, 'index']);
    Route::post('/recurring-incomes',         [RecurringIncomeController::class, 'store'])->middleware('idempotent');
    Route::get('/recurring-incomes/{recurringIncome}', [RecurringIncomeController::class, 'show']);
    Route::put('/recurring-incomes/{recurringIncome}', [RecurringIncomeController::class, 'update']);
    Route::get('/recurring-incomes/{recurringIncome}/occurrences', [RecurringIncomeController::class, 'occurrences']);
    Route::post('/recurring-incomes/{recurringIncome}/pause',   [RecurringIncomeController::class, 'pause']);
    Route::post('/recurring-incomes/{recurringIncome}/resume',  [RecurringIncomeController::class, 'resume']);
    Route::post('/recurring-incomes/{recurringIncome}/stop',    [RecurringIncomeController::class, 'stop']);
    Route::post('/recurring-incomes/{recurringIncome}/occurrences/{occurrence}/receive', [RecurringIncomeController::class, 'receive'])->middleware('idempotent');
    Route::put('/recurring-incomes/{recurringIncome}/occurrences/{occurrence}/receipt', [RecurringIncomeController::class, 'correctReceipt']);

    // Recurring expenses (expected bills), occurrences and bill calendar
    Route::get('/recurring-expenses/calendar', [RecurringExpenseController::class, 'calendar']);
    Route::post('/recurring-expenses/preview', [RecurringExpenseController::class, 'preview']);
    Route::get('/recurring-expenses',          [RecurringExpenseController::class, 'index']);
    Route::post('/recurring-expenses',         [RecurringExpenseController::class, 'store'])->middleware('idempotent');
    Route::get('/recurring-expenses/{recurringExpense}', [RecurringExpenseController::class, 'show']);
    Route::put('/recurring-expenses/{recurringExpense}', [RecurringExpenseController::class, 'update']);
    Route::get('/recurring-expenses/{recurringExpense}/occurrences', [RecurringExpenseController::class, 'occurrences']);
    Route::post('/recurring-expenses/{recurringExpense}/pause',  [RecurringExpenseController::class, 'pause']);
    Route::post('/recurring-expenses/{recurringExpense}/resume', [RecurringExpenseController::class, 'resume']);
    Route::post('/recurring-expenses/{recurringExpense}/stop',   [RecurringExpenseController::class, 'stop']);
    Route::post('/recurring-expenses/{recurringExpense}/occurrences/{occurrence}/pay', [RecurringExpenseController::class, 'pay'])->middleware('idempotent');
    Route::post('/recurring-expenses/{recurringExpense}/occurrences/{occurrence}/skip', [RecurringExpenseController::class, 'skip']);
    Route::post('/recurring-expenses/{recurringExpense}/occurrences/{occurrence}/unpaid', [RecurringExpenseController::class, 'markUnpaid']);

    // In-app bill reminders
    Route::get('/bill-reminders', [BillReminderController::class, 'index']);
    Route::post('/bill-reminders/read-all', [BillReminderController::class, 'readAll']);
    Route::post('/bill-reminders/{reminder}/read', [BillReminderController::class, 'read']);

    // Unified notification center
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{type}/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // Stage 4 spending intelligence and schedule-based cash-flow forecasting.
    // Both endpoints are read-only and use only the authenticated user's data.
    Route::get('/analytics/spending-insights', [SpendingInsightsController::class, 'show']);
    Route::get('/analytics/cash-flow-forecast', [CashFlowForecastController::class, 'show']);

    // Manual net worth entries, append-only valuation snapshots and trends.
    Route::get('/net-worth', [NetWorthController::class, 'index']);
    Route::get('/net-worth/trend', [NetWorthController::class, 'trend']);
    Route::post('/net-worth', [NetWorthController::class, 'store'])->middleware('idempotent');
    Route::get('/net-worth/{netWorthEntry}', [NetWorthController::class, 'show']);
    Route::put('/net-worth/{netWorthEntry}', [NetWorthController::class, 'update']);
    Route::post('/net-worth/{netWorthEntry}/archive', [NetWorthController::class, 'archive']);
    Route::post('/net-worth/{netWorthEntry}/restore', [NetWorthController::class, 'restore']);
    Route::post('/net-worth/{netWorthEntry}/valuations', [NetWorthController::class, 'storeValuation'])->middleware('idempotent');

    // Subscription tracker. Linking is optional and never creates or pays a
    // bill schedule; the unique link prevents duplicate obligation counting.
    Route::get('/subscriptions', [SubscriptionController::class, 'index']);
    Route::post('/subscriptions', [SubscriptionController::class, 'store'])->middleware('idempotent');
    Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show']);
    Route::put('/subscriptions/{subscription}', [SubscriptionController::class, 'update']);

    // Analytics
    Route::get('/analytics/cash-flow',        [AnalyticsController::class, 'monthlyCashFlow']);
    Route::get('/analytics/spending-by-category', [AnalyticsController::class, 'spendingByCategory']);
    Route::get('/analytics/month-comparison',     [AnalyticsController::class, 'monthComparison']);
    Route::get('/analytics/budget-status',        [AnalyticsController::class, 'budgetStatus']);
    Route::get('/analytics/budget-alerts',        [AnalyticsController::class, 'budgetAlerts']);

    // Stage 5 server-side transaction search and streaming CSV export. Both
    // use the same owner-scoped query and exclude soft-deleted ledger rows.
    Route::get('/transactions/search', [TransactionSearchController::class, 'index']);
    Route::get('/transactions/export', [TransactionSearchController::class, 'export'])
        ->middleware('throttle:20,1');

    // Per-user dashboard layout. This table stores no financial values.
    Route::get('/dashboard/preferences', [DashboardPreferenceController::class, 'index']);
    Route::put('/dashboard/preferences', [DashboardPreferenceController::class, 'update']);
    Route::delete('/dashboard/preferences', [DashboardPreferenceController::class, 'destroy']);

    // Stage 5 dedicated insights. Every endpoint is read-only, owner-scoped
    // and returns the documented "actual vs expected" separation.
    Route::get('/analytics/spending-trends', [AnalyticsInsightsController::class, 'spendingTrends']);
    Route::get('/analytics/income-analysis', [AnalyticsInsightsController::class, 'incomeAnalysis']);
    Route::get('/analytics/budget-performance', [AnalyticsInsightsController::class, 'budgetPerformance']);
    Route::get('/analytics/savings-progress', [AnalyticsInsightsController::class, 'savingsProgress']);
    Route::get('/analytics/commitments', [AnalyticsInsightsController::class, 'commitments']);
    Route::get('/analytics/data-quality', [AnalyticsInsightsController::class, 'dataQuality']);

    // Stage 5 reports centre. The preview and the CSV export share one
    // contract, so the exported totals are the totals that were displayed.
    Route::get('/reports', [ReportController::class, 'show']);
    Route::get('/reports/export', [ReportController::class, 'export'])
        ->middleware('throttle:20,1');

    // Full user-data export. It excludes credentials, session material and
    // idempotency replay bodies.
    Route::get('/export/all', [FullDataExportController::class, 'export'])
        ->middleware('throttle:10,1');

    // Stage 6 CSV import. Preview first, then confirm.
    Route::post('/import/preview', [ImportController::class, 'preview'])
        ->middleware('throttle:20,1');
    Route::post('/import', [ImportController::class, 'import'])
        ->middleware('throttle:5,1');
});
