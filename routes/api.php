<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use App\Models\User;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\AnalyticsController;

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
    // Expenses
    Route::post('/expenses',             [ExpenseController::class, 'store']);
    Route::get('/expenses',              [ExpenseController::class, 'index']);
    Route::get('/expenses/{expense}',    [ExpenseController::class, 'show']);
    Route::put('/expenses/{expense}',    [ExpenseController::class, 'update']);
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);

    // Incomes
    Route::post('/incomes',             [IncomeController::class, 'store']);
    Route::get('/incomes',              [IncomeController::class, 'index']);
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
    Route::post('/budgets',             [BudgetController::class, 'store']);
    Route::get('/budgets',              [BudgetController::class, 'index']);
    Route::get('/budgets/{budget}',     [BudgetController::class, 'show']);
    Route::put('/budgets/{budget}',     [BudgetController::class, 'update']);
    Route::delete('/budgets/{budget}',  [BudgetController::class, 'destroy']);

    // Analytics
    Route::get('/analytics/cash-flow',        [AnalyticsController::class, 'monthlyCashFlow']);
    Route::get('/analytics/spending-by-category', [AnalyticsController::class, 'spendingByCategory']);
    Route::get('/analytics/month-comparison',     [AnalyticsController::class, 'monthComparison']);
    Route::get('/analytics/budget-status',        [AnalyticsController::class, 'budgetStatus']);
    Route::get('/analytics/budget-alerts',        [AnalyticsController::class, 'budgetAlerts']);
});
