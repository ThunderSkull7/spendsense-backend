<?php

namespace App\Support;

final class DashboardWidgets
{
    /**
     * Stable widget keys shared with the frontend. Adding a widget requires a
     * backend and frontend change; unknown keys are rejected by validation.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'summary' => 'Cash-flow summary',
        'cash_flow' => 'This month vs last month',
        'spending_categories' => 'Spending by category',
        'budgets' => 'Budget health',
        'savings_goals' => 'Savings goals',
        'upcoming_bills' => 'Upcoming bills',
        'recent_transactions' => 'Recent expenses',
        'spending_insights' => 'Spending insights',
        'cash_flow_forecast' => '30-day forecast',
        'net_worth' => 'Net worth',
        'subscription_renewals' => 'Subscription renewals',
    ];

    /** @var list<string> */
    public const KEYS = [
        'summary',
        'cash_flow',
        'spending_categories',
        'budgets',
        'savings_goals',
        'upcoming_bills',
        'recent_transactions',
        'spending_insights',
        'cash_flow_forecast',
        'net_worth',
        'subscription_renewals',
    ];

    /**
     * Default order keeps the most frequently used widgets first. Net worth
     * and subscriptions are available but hidden until requested because they
     * depend on optional manual records.
     *
     * @return list<array{key: string, visible: bool}>
     */
    public static function defaults(): array
    {
        $hidden = ['net_worth', 'subscription_renewals'];

        return array_map(
            fn (string $key): array => [
                'key' => $key,
                'visible' => ! in_array($key, $hidden, true),
            ],
            self::KEYS
        );
    }

    /**
     * Keep submitted order, reject unknown keys during validation, and append
     * any omitted widgets in their default position. This makes older clients
     * safe when a new widget is added.
     *
     * @param  array<int, array{key?: mixed, visible?: mixed}>  $widgets
     * @return list<array{key: string, visible: bool}>
     */
    public static function normalize(array $widgets): array
    {
        $submitted = [];

        foreach ($widgets as $widget) {
            $key = is_array($widget) ? ($widget['key'] ?? null) : null;

            if (! is_string($key) || ! in_array($key, self::KEYS, true)) {
                continue;
            }

            if (! array_key_exists($key, $submitted)) {
                $submitted[$key] = [
                    'key' => $key,
                    'visible' => filter_var(
                        $widget['visible'] ?? true,
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE
                    ) ?? true,
                ];
            }
        }

        foreach (self::defaults() as $default) {
            if (! array_key_exists($default['key'], $submitted)) {
                $submitted[$default['key']] = $default;
            }
        }

        return array_values($submitted);
    }

    /**
     * @return array{default_widgets: list<array{key: string, visible: bool}>, labels: array<string, string>}
     */
    public static function meta(): array
    {
        return [
            'default_widgets' => self::defaults(),
            'labels' => self::LABELS,
        ];
    }
}
