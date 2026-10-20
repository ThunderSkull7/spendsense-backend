<?php

namespace App\Http\Controllers;

use App\Support\Csv;
use App\Support\FinancialCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class FullDataExportController extends Controller
{
    /**
     * A portable JSON snapshot of the authenticated user's financial data.
     * Soft-deleted ledger rows are included with deleted_at so the export is
     * honest about history. Credentials, sessions and idempotency replay data
     * are never included.
     */
    public function export(): JsonResponse
    {
        $userId = auth()->id();

        $expenses = DB::table('expenses')
            ->where('user_id', $userId)
            ->get(['id', 'category_id', 'amount', 'description', 'expense_date', 'created_at', 'updated_at', 'deleted_at']);

        $incomes = DB::table('incomes')
            ->where('user_id', $userId)
            ->get(['id', 'amount', 'source', 'income_date', 'frequency', 'notes', 'created_at', 'updated_at', 'deleted_at']);

        $budgets = DB::table('budgets')
            ->where('user_id', $userId)
            ->get(['id', 'category_id', 'limit_amount', 'month_year', 'alert_thresholds', 'created_at', 'updated_at'])
            ->map(function (object $row): array {
                $data = (array) $row;
                $data['alert_thresholds'] = $data['alert_thresholds'] === null
                    ? null
                    : json_decode((string) $data['alert_thresholds'], true);

                return $data;
            });

        $recurringExpenses = DB::table('recurring_expenses')
            ->where('user_id', $userId)
            ->get(['id', 'description', 'amount', 'category_id', 'frequency', 'start_date', 'end_date', 'status', 'reminder_days_before', 'notes', 'created_at', 'updated_at']);

        $recurringExpenseIds = $recurringExpenses->pluck('id')->all();
        $recurringExpenseOccurrences = $recurringExpenseIds === []
            ? collect()
            : DB::table('recurring_expense_occurrences')
                ->whereIn('recurring_expense_id', $recurringExpenseIds)
                ->get(['id', 'recurring_expense_id', 'occurrence_date', 'expected_amount', 'status', 'expense_id', 'paid_at', 'created_at', 'updated_at']);

        $recurringIncomes = DB::table('recurring_incomes')
            ->where('user_id', $userId)
            ->get(['id', 'name', 'amount', 'frequency', 'start_date', 'end_date', 'status', 'notes', 'created_at', 'updated_at']);

        $recurringIncomeIds = $recurringIncomes->pluck('id')->all();
        $recurringIncomeOccurrences = $recurringIncomeIds === []
            ? collect()
            : DB::table('recurring_income_occurrences')
                ->whereIn('recurring_income_id', $recurringIncomeIds)
                ->get(['id', 'recurring_income_id', 'occurrence_date', 'expected_amount', 'status', 'income_id', 'created_at', 'updated_at']);

        $savingsGoals = DB::table('savings_goals')
            ->where('user_id', $userId)
            ->get(['id', 'name', 'description', 'target_amount', 'target_date', 'status', 'notes', 'completed_at', 'archived_at', 'created_at', 'updated_at']);

        $savingsGoalIds = $savingsGoals->pluck('id')->all();
        $savingsContributions = $savingsGoalIds === []
            ? collect()
            : DB::table('savings_goal_contributions')
                ->whereIn('savings_goal_id', $savingsGoalIds)
                ->get(['id', 'savings_goal_id', 'type', 'amount', 'note', 'contributed_at', 'created_at', 'updated_at']);

        $netWorthEntries = DB::table('net_worth_entries')
            ->where('user_id', $userId)
            ->get(['id', 'name', 'type', 'category', 'institution', 'notes', 'status', 'archived_at', 'created_at', 'updated_at']);

        $netWorthEntryIds = $netWorthEntries->pluck('id')->all();
        $netWorthValuations = $netWorthEntryIds === []
            ? collect()
            : DB::table('net_worth_valuations')
                ->whereIn('net_worth_entry_id', $netWorthEntryIds)
                ->get(['id', 'net_worth_entry_id', 'value_amount', 'valuation_date', 'note', 'created_at', 'updated_at']);

        $subscriptions = DB::table('subscriptions')
            ->where('user_id', $userId)
            ->get(['id', 'name', 'provider', 'recurring_expense_id', 'amount', 'frequency', 'next_renewal_date', 'category_id', 'status', 'notes', 'created_at', 'updated_at']);

        $billReminders = DB::table('bill_reminders')
            ->where('user_id', $userId)
            ->get(['id', 'recurring_expense_occurrence_id', 'type', 'window_days', 'due_date', 'read_at', 'created_at', 'updated_at']);

        $budgetAlerts = DB::table('budget_alerts')
            ->where('user_id', $userId)
            ->get(['id', 'budget_id', 'threshold', 'period_month', 'spent_amount', 'limit_amount', 'read_at', 'created_at', 'updated_at']);

        $preferences = DB::table('dashboard_preferences')
            ->where('user_id', $userId)
            ->first(['widgets', 'created_at', 'updated_at']);

        if ($preferences !== null) {
            $preferences = (array) $preferences;
            $preferences['widgets'] = json_decode((string) $preferences['widgets'], true);
        }

        $categoryIds = array_values(array_unique(array_filter(array_merge(
            $expenses->pluck('category_id')->all(),
            $budgets->pluck('category_id')->all(),
            $recurringExpenses->pluck('category_id')->all(),
            $subscriptions->pluck('category_id')->all()
        ))));

        $categories = $categoryIds === []
            ? collect()
            : DB::table('categories')
                ->whereIn('id', $categoryIds)
                ->get(['id', 'name', 'type']);

        $user = auth()->user();
        $generatedAt = FinancialCalendar::now()->toIso8601String();

        $payload = [
            'meta' => [
                'format' => 'spendsense-user-data',
                'format_version' => 1,
                'generated_at' => $generatedAt,
                'timezone' => FinancialCalendar::TIMEZONE,
                'basis' => 'Inclusive UTC calendar dates. Amounts are decimal strings in the application currency.',
                'soft_deleted_rows' => 'Expenses and incomes include deleted_at so trash history is not silently lost.',
                'counts' => [
                    'expenses' => $expenses->count(),
                    'incomes' => $incomes->count(),
                    'budgets' => $budgets->count(),
                    'recurring_expenses' => $recurringExpenses->count(),
                    'recurring_expense_occurrences' => $recurringExpenseOccurrences->count(),
                    'recurring_incomes' => $recurringIncomes->count(),
                    'recurring_income_occurrences' => $recurringIncomeOccurrences->count(),
                    'savings_goals' => $savingsGoals->count(),
                    'savings_goal_contributions' => $savingsContributions->count(),
                    'net_worth_entries' => $netWorthEntries->count(),
                    'net_worth_valuations' => $netWorthValuations->count(),
                    'subscriptions' => $subscriptions->count(),
                    'bill_reminders' => $billReminders->count(),
                    'budget_alerts' => $budgetAlerts->count(),
                ],
            ],
            'account' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
                'created_at' => $user?->created_at?->toIso8601String(),
            ],
            'categories' => $categories,
            'expenses' => $expenses,
            'incomes' => $incomes,
            'budgets' => $budgets,
            'recurring_expenses' => $recurringExpenses,
            'recurring_expense_occurrences' => $recurringExpenseOccurrences,
            'recurring_incomes' => $recurringIncomes,
            'recurring_income_occurrences' => $recurringIncomeOccurrences,
            'savings_goals' => $savingsGoals,
            'savings_goal_contributions' => $savingsContributions,
            'net_worth_entries' => $netWorthEntries,
            'net_worth_valuations' => $netWorthValuations,
            'subscriptions' => $subscriptions,
            'bill_reminders' => $billReminders,
            'budget_alerts' => $budgetAlerts,
            'dashboard_preferences' => $preferences,
            'notes' => [
                'This export belongs only to the authenticated account.',
                'Savings contributions are internal tracking entries and are not income or expenses.',
                'Linked subscriptions and recurring schedules are exported as stored; no obligation is duplicated or synthesized.',
                'No password hash, session token, API token, idempotency replay body or other credential is included.',
            ],
        ];

        $filename = Csv::filename('spendsense-user-data-'.$generatedAt, 'json');

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
