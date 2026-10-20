<?php

namespace App\Services;

use App\Support\Csv;

class ReportCsvService
{
    /**
     * @param  array<string, mixed>  $report
     * @return iterable<int, array<int, string>>
     */
    public function rows(array $report): iterable
    {
        $cell = fn (mixed $value): string => Csv::cell($value);
        $period = $report['period'];

        yield [$cell('SpendSense financial report')];
        yield [$cell('Period'), $cell($period['from']), $cell($period['to']), $cell($period['label'])];
        yield [$cell('Boundary'), $cell($period['boundary']), $cell('Timezone'), $cell($period['timezone'])];
        yield [$cell('Sections'), $cell(implode(', ', $report['sections']))];

        if (($report['comparison_period'] ?? null) !== null) {
            yield [$cell('Comparison period'), $cell($report['comparison_period']['from']), $cell($report['comparison_period']['to']), $cell($report['comparison_period']['basis'])];
        }

        yield [];
        yield [$cell('Summary')];
        yield [$cell('Metric'), $cell('Current period'), $cell('Comparison period'), $cell('Change'), $cell('Change %')];
        $summary = $report['summary'];
        $previous = $summary['previous'] ?? [];
        $changes = $summary['changes'] ?? [];
        yield [
            $cell('Income'),
            $cell($summary['income_amount']),
            $cell($previous['income_amount'] ?? ''),
            $cell($changes['income']['amount'] ?? ''),
            $cell($changes['income']['percent'] ?? ''),
        ];
        yield [
            $cell('Expenses'),
            $cell($summary['expense_amount']),
            $cell($previous['expense_amount'] ?? ''),
            $cell($changes['expense']['amount'] ?? ''),
            $cell($changes['expense']['percent'] ?? ''),
        ];
        yield [
            $cell('Net cash flow'),
            $cell($summary['net_amount']),
            $cell($previous['net_amount'] ?? ''),
            $cell($changes['net']['amount'] ?? ''),
            $cell($changes['net']['percent'] ?? ''),
        ];
        yield [$cell('Opening balance'), $cell('Not available'), $cell($report['balances']['opening']['reason'])];
        yield [$cell('Ending balance'), $cell('Not available'), $cell($report['balances']['ending']['reason'])];

        $expectations = $report['expectations'];
        yield [];
        yield [$cell('Expected obligations (not included in actual totals)')];
        yield [$cell('Upcoming bills next 30 days'), $cell($expectations['upcoming_bill_count']), $cell($expectations['upcoming_bills_amount'])];
        yield [$cell('Subscription estimated monthly cost'), $cell($expectations['subscription_estimated_monthly_cost'])];
        yield [$cell('Subscription estimated annual cost'), $cell($expectations['subscription_estimated_annual_cost'])];
        yield [$cell('Expected obligation note'), $cell($expectations['note'])];

        if (($report['comparison'] ?? null) !== null) {
            yield [];
            yield [$cell('Comparison drivers')];
            yield [$cell('Type'), $cell('Name'), $cell('Current'), $cell('Previous'), $cell('Change'), $cell('Change %')];

            foreach (($report['comparison']['drivers']['expense_categories'] ?? []) as $row) {
                yield [
                    $cell('Expense category'),
                    $cell($row['category_name']),
                    $cell($row['current_amount']),
                    $cell($row['previous_amount']),
                    $cell($row['change_amount']),
                    $cell($row['change_percent'] ?? ''),
                ];
            }

            foreach (($report['comparison']['drivers']['income_sources'] ?? []) as $row) {
                yield [
                    $cell('Income source'),
                    $cell($row['source']),
                    $cell($row['current_amount']),
                    $cell($row['previous_amount']),
                    $cell($row['change_amount']),
                    $cell($row['change_percent'] ?? ''),
                ];
            }
        }

        if (($report['monthly'] ?? null) !== null) {
            yield [];
            yield [$cell('Monthly actuals')];
            yield [$cell('Month'), $cell('Income'), $cell('Expenses'), $cell('Net'), $cell('Partial month')];

            foreach ($report['monthly'] as $row) {
                yield [
                    $cell($row['month']),
                    $cell($row['income_amount']),
                    $cell($row['expense_amount']),
                    $cell($row['net_amount']),
                    $cell($row['is_partial'] ? 'yes' : 'no'),
                ];
            }
        }

        if (($report['categories'] ?? null) !== null) {
            yield [];
            yield [$cell('Expenses by category')];
            yield [$cell('Category'), $cell('Amount'), $cell('Transactions'), $cell('Share %')];

            foreach ($report['categories'] as $row) {
                yield [
                    $cell($row['category_name']),
                    $cell($row['amount']),
                    $cell($row['transaction_count']),
                    $cell($row['share_percent'] ?? ''),
                ];
            }
        }

        if (($report['income_sources'] ?? null) !== null) {
            yield [];
            yield [$cell('Income by source')];
            yield [$cell('Source'), $cell('Amount'), $cell('Transactions'), $cell('Share %')];

            foreach ($report['income_sources'] as $row) {
                yield [
                    $cell($row['source']),
                    $cell($row['amount']),
                    $cell($row['transaction_count']),
                    $cell($row['share_percent'] ?? ''),
                ];
            }
        }

        if (($report['budgets'] ?? null) !== null) {
            yield [];
            yield [$cell('Budget performance (whole calendar months)')];
            yield [$cell('Month'), $cell('Category'), $cell('Budget'), $cell('Spent'), $cell('Remaining'), $cell('Used %')];

            foreach (($report['budgets']['items'] ?? []) as $row) {
                yield [
                    $cell($row['month']),
                    $cell($row['category_name']),
                    $cell($row['limit_amount']),
                    $cell($row['spent_amount']),
                    $cell($row['remaining_amount']),
                    $cell($row['utilization_percent'] ?? ''),
                ];
            }
        }

        if (($report['savings'] ?? null) !== null) {
            yield [];
            yield [$cell('Savings goals (internal tracking; not expenses)')];
            yield [$cell('Goal'), $cell('Status'), $cell('Target'), $cell('Saved'), $cell('Remaining'), $cell('Progress %'), $cell('Estimated completion')];

            foreach (($report['savings']['goals'] ?? []) as $row) {
                yield [
                    $cell($row['name']),
                    $cell($row['status']),
                    $cell($row['target_amount']),
                    $cell($row['saved_amount']),
                    $cell($row['remaining_amount']),
                    $cell($row['progress_percent'] ?? ''),
                    $cell($row['estimated_completion']['projected_month'] ?? $row['estimated_completion']['reason'] ?? ''),
                ];
            }
        }

        if (($report['upcoming_bills'] ?? null) !== null) {
            yield [];
            yield [$cell('Upcoming bills (expected obligations; not actual expenses)')];
            yield [$cell('Date'), $cell('Name'), $cell('Category'), $cell('Amount'), $cell('Status')];

            foreach ($report['upcoming_bills'] as $row) {
                yield [
                    $cell($row['date']),
                    $cell($row['name']),
                    $cell($row['category_name'] ?? ''),
                    $cell($row['amount']),
                    $cell($row['status']),
                ];
            }
        }

        if (($report['subscriptions'] ?? null) !== null) {
            $subscriptions = $report['subscriptions'];
            $summary = $subscriptions['summary'];

            yield [];
            yield [$cell('Subscriptions (estimates; linked costs are not double-counted)')];
            yield [$cell('Active count'), $cell($summary['active_count'] ?? 0)];
            yield [$cell('Linked count'), $cell($summary['linked_count'] ?? 0)];
            yield [$cell('Standalone count'), $cell($summary['standalone_count'] ?? 0)];
            yield [$cell('Estimated monthly cost'), $cell($summary['estimated_monthly_cost'] ?? '0.00')];
            yield [$cell('Estimated annual cost'), $cell($summary['estimated_annual_cost'] ?? '0.00')];
        }

        if (($report['commitments'] ?? null) !== null) {
            $summary = $report['commitments']['summary'];

            yield [];
            yield [$cell('Combined commitments (linked schedules counted once)')];
            yield [$cell('Combined monthly commitment'), $cell($summary['combined_monthly_commitment'])];
            yield [$cell('Combined annual commitment'), $cell($summary['combined_annual_commitment'])];
            yield [$cell('Bill schedules monthly'), $cell($summary['bill_schedule_monthly_cost'])];
            yield [$cell('Standalone subscriptions monthly'), $cell($summary['standalone_subscription_monthly_cost'])];
            yield [$cell('Linked subscriptions monthly (already in bill schedules)'), $cell($summary['linked_subscription_monthly_cost'])];
        }

        if (($report['forecast'] ?? null) !== null) {
            $forecast = $report['forecast'];

            yield [];
            yield [$cell('Cash-flow forecast (next 30 days; expectations only)')];
            yield [$cell('Expected income'), $cell($forecast['expected_income'])];
            yield [$cell('Expected expenses'), $cell($forecast['expected_expenses'])];
            yield [$cell('Projected net cash flow'), $cell($forecast['projected_net_cash_flow'])];
            yield [$cell('Balance status'), $cell($forecast['starting_balance_status'])];
            yield [$cell('Negative-date status'), $cell($forecast['negative_cash_flow_status'])];
        }

        if (($report['net_worth'] ?? null) !== null) {
            $netWorth = $report['net_worth'];
            $summary = $netWorth['summary'];

            yield [];
            yield [$cell('Net worth snapshot (manual valuations; not available cash)')];
            yield [$cell('As of'), $cell($netWorth['as_of'])];
            yield [$cell('Assets'), $cell($summary['total_assets'] ?? '0.00')];
            yield [$cell('Liabilities'), $cell($summary['total_liabilities'] ?? '0.00')];
            yield [$cell('Net worth'), $cell($summary['net_worth'] ?? '0.00')];
        }

        if (($report['expense_review'] ?? null) !== null) {
            $review = $report['expense_review'];

            yield [];
            yield [$cell('Expense review signals')];
            yield [$cell('Average expense'), $cell($review['average_expense_amount'])];
            yield [$cell('Large-expense threshold'), $cell($review['large_expense_threshold'] ?? 'not available')];
            yield [$cell('Review note'), $cell($review['note'])];
            yield [$cell('Date'), $cell('Description'), $cell('Category'), $cell('Amount')];

            foreach (($review['largest_expenses'] ?? []) as $row) {
                yield [$cell($row['date']), $cell($row['description']), $cell($row['category_name'] ?? ''), $cell($row['amount'])];
            }
        }

        yield [];
        yield [$cell('Notes')];

        foreach (($report['notes'] ?? []) as $note) {
            yield [$cell($note)];
        }

        yield [];
        yield [$cell('Generated at'), $cell(gmdate('Y-m-d H:i:s').' UTC')];
    }
}
