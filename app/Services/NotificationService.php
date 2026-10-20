<?php

namespace App\Services;

use App\Models\BillReminder;
use App\Models\BudgetAlert;
use App\Models\Budget;
use App\Models\RecurringExpenseOccurrence;
use App\Models\Expense;
use App\Support\FinancialCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class NotificationService
{
    public function __construct(
        private readonly BillReminderService $billReminders,
        private readonly BudgetAlertService $budgetAlerts
    ) {}

    /**
     * Get all notifications for the user with filtering options.
     *
     * @param  array{filter?: string, month?: string, limit?: int, offset?: int}  $filters
     * @return array<string, mixed>
     */
    public function payloadForUser(int $userId, array $filters = []): array
    {
        $filter = $filters['filter'] ?? 'all'; // all, unread, read
        $month = isset($filters['month'])
            ? FinancialCalendar::parseMonth($filters['month'], 'month')
            : FinancialCalendar::today()->startOfMonth();
        $limit = (int) ($filters['limit'] ?? 100);
        $offset = (int) ($filters['offset'] ?? 0);

        // Get bill reminders (reconcile first)
        $this->billReminders->syncForUser($userId);
        $reminders = $this->billReminders->payloadForUser($userId)['reminders'];

        // Get budget alerts for the current month
        $today = FinancialCalendar::today();
        $month = FinancialCalendar::parseMonth(
            $filters['month'] ?? $today->format('Y-m'),
            'month'
        );
        $budgetData = $this->budgetAlerts->payloadForUser($userId, $filters['month'] ?? null);
        $budgetAlerts = $budgetData['alerts'] ?? [];

        // Transform reminders to unified format
        $unifiedReminders = $this->transformReminders($reminders);

        // Transform budget alerts to unified format
        $unifiedBudgetAlerts = $this->transformBudgetAlerts($budgetAlerts);

        // Combine and sort by date (most recent first)
        $allNotifications = collect($unifiedReminders)
            ->merge($unifiedBudgetAlerts)
            ->sortByDesc(fn ($item) => $item['sort_date'])
            ->values();

        // Apply filter
        $filtered = $this->applyFilter($allNotifications, $filter);

        // Apply pagination
        $paginated = $filtered->slice($offset, $limit)->values();

        // Calculate summary
        $allNotificationsCollection = collect($allNotifications);
        $totalCount = $allNotificationsCollection->count();
        $unreadCount = $allNotificationsCollection->where('is_read', false)->count();
        $readCount = $totalCount - $unreadCount;

        return [
            'notifications' => $paginated->all(),
            'summary' => [
                'total' => $totalCount,
                'unread' => $unreadCount,
                'read' => $readCount,
            ],
            'filters' => [
                'filter' => $filter,
                'month' => $month->format('Y-m'),
            ],
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => $filtered->count() > ($offset + $limit),
            ],
        ];
    }

    /**
     * Mark a specific notification as read.
     */
    public function markRead(int $userId, string $type, int $id): array
    {
        if ($type === 'bill_reminder') {
            $reminder = $this->billReminders->markRead($userId, $id);
            return $this->transformReminder($reminder);
        }

        if ($type === 'budget_alert') {
            $alert = $this->budgetAlerts->markRead($userId, $id);
            return $this->transformBudgetAlert($alert);
        }

        throw new \InvalidArgumentException('Invalid notification type');
    }

    /**
     * Mark all notifications as read (or filtered subset).
     */
    public function markAllRead(int $userId, array $filters = []): int
    {
        $count = 0;

        // Bill reminders - mark all
        $count += $this->billReminders->markAllRead($userId);

        // Budget alerts - mark all for the current month
        $month = $filters['month'] ?? null;
        $count += $this->budgetAlerts->markAllRead(
            auth()->id(),
            $filters['month'] ?? FinancialCalendar::today()->format('Y-m')
        );

        return $count;
    }

    /**
     * Apply filter to notifications.
     */
    private function applyFilter(Collection $notifications, string $filter): Collection
    {
        return match ($filter) {
            'unread' => $notifications->filter(fn ($n) => !$n['is_read']),
            'read' => $notifications->filter(fn ($n) => $n['is_read']),
            default => $notifications,
        };
    }

    /**
     * Transform bill reminders to unified format.
     */
    private function transformReminders(array $reminders): array
    {
        return array_map(function (array $reminder): array {
            return [
                'id' => $reminder['id'],
                'type' => 'bill_reminder',
                'source_id' => $reminder['occurrence']['id'] ?? null,
                'title' => $reminder['message'],
                'message' => $reminder['message'],
                'type_label' => $reminder['type_label'] ?? ucfirst($reminder['type']),
                'status' => $reminder['type'], // upcoming, due, overdue
                'status_label' => $reminder['type_label'] ?? ucfirst($reminder['type']),
                'amount' => $reminder['occurrence']['expected_amount'] ?? '0.00',
                'date' => $reminder['due_date'],
                'sort_date' => $reminder['due_date'] . ' ' . ($reminder['occurrence']['id'] ?? '0'),
                'is_read' => $reminder['is_read'],
                'read_at' => $reminder['read_at'],
                'url' => '../recurring-expenses/recurring-expenses.html?month=' . (isset($reminder['due_date']) ? substr($reminder['due_date'], 0, 7) : '') . '&bill=' . ($reminder['occurrence']['id'] ?? ''),
                'category' => $reminder['schedule']['category']['name'] ?? null,
                'schedule_id' => $reminder['schedule']['id'] ?? null,
            ];
        }, $reminders);
    }

    /**
     * Transform budget alerts to unified format.
     */
    private function transformBudgetAlerts(array $alerts): array
    {
        return array_map(function (array $alert): array {
            return [
                'id' => $alert['id'],
                'type' => 'budget_alert',
                'source_id' => $alert['budget_id'] ?? null,
                'title' => $alert['message'] ?? 'Budget alert',
                'message' => $alert['message'] ?? 'Budget threshold reached',
                'type_label' => $alert['threshold'] . '% alert',
                'status' => $alert['percentage_used'] > 100 ? 'overspent' : 'warning',
                'status_label' => $alert['percentage_used'] > 100 ? 'Over limit' : 'Near limit',
                'amount' => $alert['spent_amount'] ?? '0.00',
                'limit_amount' => $alert['limit_amount'] ?? '0.00',
                'date' => $alert['period_month'] . '-01',
                'sort_date' => $alert['period_month'] . '-01 ' . ($alert['id'] ?? '0'),
                'is_read' => $alert['is_read'],
                'read_at' => $alert['read_at'],
                'url' => '../budget-planning/budget.html?month=' . ($alert['period_month'] ?? '') . '#budget-' . ($alert['budget_id'] ?? ''),
                'category' => $alert['category']['name'] ?? 'Budget category',
                'budget_id' => $alert['budget_id'] ?? null,
                'threshold' => $alert['threshold'] ?? 0,
                'percentage_used' => $alert['percentage_used'] ?? 0,
            ];
        }, $alerts);
    }
}