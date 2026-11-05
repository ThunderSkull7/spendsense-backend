# SpendSense Backend — API Reference

This document provides the complete API specification for the SpendSense Laravel 12 REST API. Every endpoint corresponds directly to route definitions in `routes/api.php`.

---

## 1. Global Conventions

- **Base URL:**
  - Production (Browser): `https://spendsensehq.pages.dev/api` (via Cloudflare Pages reverse proxy)
  - Production (Direct): `https://spendsense-api-t0up.onrender.com/api`
  - Local Development: `http://127.0.0.1:8002/api`
- **Content Type:** `application/json` (except streaming CSV exports).
- **Authentication:** First-party session cookie (`spendsense_session`) managed via Laravel Sanctum.
- **CSRF Protection:** State-changing requests (`POST`, `PUT`, `DELETE`) require the `X-XSRF-TOKEN` header matching the `XSRF-TOKEN` cookie issued by `/sanctum/csrf-cookie`.
- **Idempotency:** Write mutations marked with `[Idempotent]` accept an optional `Idempotency-Key` header (`^[A-Za-z0-9._:-]{1,128}$`). If supplied, completed responses are cached for 24 hours and replayed on matching retries with `Idempotent-Replay: true`.

---

## 2. Authentication & Session Management

| Method | Endpoint | Auth | Throttled | Description |
| :--- | :--- | :--- | :--- | :--- |
| `GET` | `/sanctum/csrf-cookie` | Public | No | Sets the `XSRF-TOKEN` cookie required for stateful requests. |
| `POST` | `/login` | Public | 6/min | Authenticates user credentials and starts an authenticated session. |
| `POST` | `/register` | Public | 6/min | Registers a new account, hashes password, and starts session. |
| `POST` | `/logout` | Public/Session | No | Terminates session and invalidates CSRF token. |

### `POST /login`
- **Request Body:**
  ```json
  {
    "email": "user@example.com",
    "password": "SecretPassword123!",
    "remember": true
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "user": {
      "id": 1,
      "name": "Jane Doe",
      "email": "user@example.com",
      "created_at": "2026-01-15T08:30:00.000000Z",
      "updated_at": "2026-01-15T08:30:00.000000Z"
    }
  }
  ```
- **Error (401 Unauthorized):**
  ```json
  {
    "message": "Invalid credentials"
  }
  ```

---

## 3. User Profile & Account

All routes require `auth:sanctum`.

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/user` | Returns authenticated user profile and account details. |
| `PUT` | `/user` | Updates account name, email, or password. |

---

## 4. Expense Management

All routes require `auth:sanctum`. Identifier parameter `{expense}` is constrained to numeric IDs `[0-9]+`.

| Method | Endpoint | Middleware | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/expenses` | None | Lists paginated expenses with optional search and date filters. |
| `POST` | `/expenses` | `idempotent` | Creates a new expense entry. |
| `GET` | `/expenses/{expense}` | None | Retrieves a single expense by ID. |
| `PUT` | `/expenses/{expense}` | None | Updates an existing expense entry. |
| `DELETE` | `/expenses/{expense}` | None | Soft-deletes an expense entry. |
| `GET` | `/expenses/trash` | None | Lists soft-deleted expenses. |
| `POST` | `/expenses/{expense}/restore` | None | Restores a soft-deleted expense. |

### `POST /expenses`
- **Headers:** `Idempotency-Key: <UUID>` (optional)
- **Request Body:**
  ```json
  {
    "amount": 42.50,
    "category_id": 3,
    "date": "2026-11-05",
    "description": "Weekly Groceries",
    "notes": "Purchased organic produce"
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "id": 142,
    "user_id": 1,
    "category_id": 3,
    "amount": "42.50",
    "date": "2026-11-05",
    "description": "Weekly Groceries",
    "notes": "Purchased organic produce",
    "created_at": "2026-11-05T14:30:00.000000Z",
    "updated_at": "2026-11-05T14:30:00.000000Z"
  }
  ```

---

## 5. Income Management

All routes require `auth:sanctum`. Identifier parameter `{income}` is constrained to `[0-9]+`.

| Method | Endpoint | Middleware | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/incomes` | None | Lists paginated income entries. |
| `POST` | `/incomes` | `idempotent` | Records new income. |
| `GET` | `/incomes/{income}` | None | Retrieves income record by ID. |
| `PUT` | `/incomes/{income}` | None | Updates existing income record. |
| `DELETE` | `/incomes/{income}` | None | Soft-deletes income record. |
| `GET` | `/incomes/trash` | None | Lists soft-deleted income records. |
| `POST` | `/incomes/{income}/restore` | None | Restores soft-deleted income. |

---

## 6. Categories & Taxonomy

All routes require `auth:sanctum`. System provides shared defaults (`is_default: true`) alongside user-created custom categories.

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/categories` | Lists all categories accessible to user (shared defaults + user private). |
| `POST` | `/categories` | Creates a user-owned custom category. |
| `GET` | `/categories/{category}` | Retrieves single category details. |
| `PUT` | `/categories/{category}` | Updates private category (shared defaults are protected). |
| `DELETE` | `/categories/{category}` | Deletes private category (shared defaults cannot be deleted). |

### `GET /categories`
- **Response (200 OK):**
  ```json
  [
    {
      "id": 1,
      "name": "Food & Dining",
      "type": "expense",
      "is_default": true,
      "user_id": null
    },
    {
      "id": 15,
      "name": "Consulting Freelance",
      "type": "income",
      "is_default": false,
      "user_id": 1
    }
  ]
  ```

---

## 7. Budgeting & Planning

All routes require `auth:sanctum`. Budgets are constrained by unique `(user_id, category_id, period_start, period_end)`.

| Method | Endpoint | Middleware | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/budgets` | None | Lists user budgets with current spending totals. |
| `POST` | `/budgets` | `idempotent` | Creates a new category budget for a period. |
| `GET` | `/budgets/{budget}` | None | Retrieves specific budget details. |
| `PUT` | `/budgets/{budget}` | None | Updates budget amount or alert thresholds. |
| `DELETE` | `/budgets/{budget}` | None | Deletes budget definition. |

---

## 8. Savings Goals

Savings goals manage target amounts and contributions independently from ledger records.

| Method | Endpoint | Middleware | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/savings-goals` | None | Lists all active savings goals. |
| `POST` | `/savings-goals` | `idempotent` | Creates a new savings goal. |
| `GET` | `/savings-goals/{savingsGoal}` | None | Retrieves goal progress and contribution history. |
| `PUT` | `/savings-goals/{savingsGoal}` | None | Updates goal name, target amount, or deadline. |
| `POST` | `/savings-goals/{savingsGoal}/archive` | None | Archives a completed or discontinued goal. |
| `POST` | `/savings-goals/{savingsGoal}/restore` | None | Restores an archived goal. |
| `POST` | `/savings-goals/{savingsGoal}/contributions` | `idempotent` | Records a financial contribution towards the goal. |
| `POST` | `/savings-goals/{savingsGoal}/corrections` | `idempotent` | Records a ledger correction to adjust goal balance. |

---

## 9. Budget Alerts & In-App Notifications

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/budget-alerts` | Retrieves in-app alerts triggered when spending reaches thresholds. |
| `POST` | `/budget-alerts/read-all` | Marks all budget alerts as read. |
| `POST` | `/budget-alerts/{budgetAlert}/read` | Marks a single budget alert as read. |
| `GET` | `/bill-reminders` | Retrieves upcoming and overdue bill reminders. |
| `POST` | `/bill-reminders/read-all` | Marks all bill reminders as read. |
| `POST` | `/bill-reminders/{reminder}/read` | Marks a single bill reminder as read. |
| `GET` | `/notifications` | Unified feed of budget alerts and bill reminders. |
| `POST` | `/notifications/{type}/{id}/read` | Marks item in unified notification feed as read. |
| `POST` | `/notifications/read-all` | Marks all unified notifications as read. |

---

## 10. Recurring Incomes & Expenses (Schedules & Bills)

Manage recurring income obligations and bill calendars. Occurrences represent projected dates; paying/receiving an occurrence realizes the transaction in the ledger.

### Recurring Incomes
| Method | Endpoint | Middleware | Description |
| :--- | :--- | :--- | :--- |
| `POST` | `/recurring-incomes/preview` | None | Previews generated occurrence dates without saving. |
| `GET` | `/recurring-incomes` | None | Lists recurring income schedules. |
| `POST` | `/recurring-incomes` | `idempotent` | Creates recurring income schedule. |
| `GET` | `/recurring-incomes/{recurringIncome}` | None | Shows schedule details and occurrence list. |
| `PUT` | `/recurring-incomes/{recurringIncome}` | None | Updates schedule properties. |
| `GET` | `/recurring-incomes/{recurringIncome}/occurrences` | None | Retrieves planned occurrence entries. |
| `POST` | `/recurring-incomes/{recurringIncome}/pause` | None | Pauses schedule generation. |
| `POST` | `/recurring-incomes/{recurringIncome}/resume` | None | Resumes paused schedule. |
| `POST` | `/recurring-incomes/{recurringIncome}/stop` | None | Permanently terminates schedule. |
| `POST` | `/recurring-incomes/{recurringIncome}/occurrences/{occurrence}/receive` | `idempotent` | Marks occurrence as received and creates income record. |
| `PUT` | `/recurring-incomes/{recurringIncome}/occurrences/{occurrence}/receipt` | None | Adjusts received income record link. |

### Recurring Expenses (Bills)
| Method | Endpoint | Middleware | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/recurring-expenses/calendar` | None | Calendar view of bills due for a month. |
| `POST` | `/recurring-expenses/preview` | None | Previews bill occurrences before creation. |
| `GET` | `/recurring-expenses` | None | Lists active recurring bill schedules. |
| `POST` | `/recurring-expenses` | `idempotent` | Creates recurring expense schedule. |
| `GET` | `/recurring-expenses/{recurringExpense}` | None | Shows schedule details. |
| `PUT` | `/recurring-expenses/{recurringExpense}` | None | Updates schedule details. |
| `GET` | `/recurring-expenses/{recurringExpense}/occurrences` | None | Lists bill occurrences. |
| `POST` | `/recurring-expenses/{recurringExpense}/pause` | None | Pauses bill schedule. |
| `POST` | `/recurring-expenses/{recurringExpense}/resume` | None | Resumes bill schedule. |
| `POST` | `/recurring-expenses/{recurringExpense}/stop` | None | Permanently stops bill schedule. |
| `POST` | `/recurring-expenses/{recurringExpense}/occurrences/{occurrence}/pay` | `idempotent` | Marks bill as paid and posts expense to ledger. |
| `POST` | `/recurring-expenses/{recurringExpense}/occurrences/{occurrence}/skip` | None | Skips bill occurrence without posting expense. |
| `POST` | `/recurring-expenses/{recurringExpense}/occurrences/{occurrence}/unpaid` | None | Reverts marked occurrence back to unpaid. |

---

## 11. Net Worth Tracker & Subscriptions

### Net Worth
| Method | Endpoint | Middleware | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/net-worth` | None | Lists all asset and liability accounts. |
| `GET` | `/net-worth/trend` | None | Returns historical net worth progression. |
| `POST` | `/net-worth` | `idempotent` | Creates an asset or liability account. |
| `GET` | `/net-worth/{netWorthEntry}` | None | Shows account details and valuation history. |
| `PUT` | `/net-worth/{netWorthEntry}` | None | Updates account properties. |
| `POST` | `/net-worth/{netWorthEntry}/archive` | None | Archives account. |
| `POST` | `/net-worth/{netWorthEntry}/restore` | None | Restores archived account. |
| `POST` | `/net-worth/{netWorthEntry}/valuations` | `idempotent` | Appends a point-in-time valuation snapshot. |

### Subscriptions
| Method | Endpoint | Middleware | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/subscriptions` | None | Lists tracked recurring subscriptions. |
| `POST` | `/subscriptions` | `idempotent` | Creates a subscription tracker. |
| `GET` | `/subscriptions/{subscription}` | None | Retrieves subscription details. |
| `PUT` | `/subscriptions/{subscription}` | None | Updates subscription tracker. |

---

## 12. Analytics, Insights, Search & Reports

### Analytics Core
| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/analytics/cash-flow` | Monthly cash flow totals (inflow vs outflow). |
| `GET` | `/analytics/spending-by-category` | Category-level spending distribution. |
| `GET` | `/analytics/month-comparison` | Month-over-month expenditure comparison. |
| `GET` | `/analytics/budget-status` | High-level status of active budgets. |
| `GET` | `/analytics/budget-alerts` | Active alert summary for dashboard widgets. |

### Dedicated Insights & Forecasting
| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/analytics/spending-insights` | Spending velocity and anomaly detection. |
| `GET` | `/analytics/cash-flow-forecast` | Predictive forward-looking cash flow forecast. |
| `GET` | `/analytics/spending-trends` | Multi-month trend trajectory per category. |
| `GET` | `/analytics/income-analysis` | Income stability and source concentration. |
| `GET` | `/analytics/budget-performance` | Budget adherence rates over time. |
| `GET` | `/analytics/savings-progress` | Trajectory towards active savings goals. |
| `GET` | `/analytics/commitments` | Fixed obligations vs discretionary spending ratio. |
| `GET` | `/analytics/data-quality` | Hygiene audit detecting unclassified transactions. |

### Search, Reports & Data Portability
| Method | Endpoint | Throttled | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/transactions/search` | No | Unified search across income and expense ledgers. |
| `GET` | `/transactions/export` | 20/min | Streams transaction search results as a CSV file. |
| `GET` | `/reports` | No | Aggregated financial report for period (`from`, `to`). |
| `GET` | `/reports/export` | 20/min | Streams period report as a structured CSV file. |
| `GET` | `/export/all` | 10/min | Full JSON archive of user account data. |
| `POST` | `/import/preview` | 20/min | Previews uploaded CSV transactions and mappings. |
| `POST` | `/import` | 5/min | Confirms and commits imported transactions to ledger. |
| `GET` | `/dashboard/preferences` | No | Retrieves user layout widget preferences. |
| `PUT` | `/dashboard/preferences` | No | Saves user layout widget preferences. |
| `DELETE` | `/dashboard/preferences` | No | Resets dashboard layout to defaults. |

---

## 13. Summary Route Statistics

- **Total API Routes Defined in `routes/api.php`:** **106 routes**
- **Public Routes:** 4 (`/sanctum/csrf-cookie`, `/login`, `/register`, `/logout`)
- **Protected Routes (`auth:sanctum`):** 102
- **Idempotency-Protected Mutations (`[Idempotent]`):** 16 mutation endpoints
- **Rate-Throttled Endpoints:** 8 endpoints (auth, export, and import operations)
