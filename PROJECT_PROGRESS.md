# SpendSense Backend — Progress Log

Living notes on what has been built in this repository. Last reviewed: **11 October 2026**.

## What this project is

SpendSense is a Laravel 12 (PHP 8.2) API for personal finance: expenses, incomes, categories, and budgets. The GitHub remote is [ThunderSkull7/spendsense-backend](https://github.com/ThunderSkull7/spendsense-backend). The default Laravel README has not been replaced with product docs.

Stack in use today:

- Laravel 12 + Eloquent
- Pest 3 for tests (still the skeleton examples)
- Laravel Boost with Claude Code skills (`laravel-best-practices`, `pest-testing`)
- Vite + default welcome page (no product UI)
- Postman workspace stubs (empty collection)

There is no authentication package (Sanctum/Passport), no policies, and no frontend app in this repo.

---

## Timeline

| Date | Commit | What landed |
| --- | --- | --- |
| 9 May 2026 | `5c712f1` Initial commit | Laravel app, domain models, first migrations, expense CRUD routes/controller |
| 12 May 2026 | `23d3d74` Built SpendSense backend foundation with migrations validation and seeders | Relational schema, form requests, category seeder, factories (empty), drop `transactions` |

Branch: `main` (in sync with `origin/main`). There is a Copilot branch `copilot/use-github-student-domain` that still points at the initial commit only.

---

## Phase 1 — Bootstrap and first expense API (9 May 2026)

Started from a Laravel 12 skeleton, then added SpendSense domain files.

**Models created** (stubs except Expense):

- `User` — Laravel default
- `Expense` — fillable: `title`, `amount`, `category`, `description`, `expense_date`
- `Income`, `Category`, `Budget`, `Transaction` — empty models

**First expenses table** was a flat schema (no user or category FKs):

- `title`, `amount`, `category` (string), `description`, `expense_date`

**API** in `routes/api.php` (Laravel prefixes these with `/api`):

| Method | Path | Action |
| --- | --- | --- |
| POST | `/api/expenses` | create |
| GET | `/api/expenses` | list all |
| PUT | `/api/expenses/{id}` | update |
| DELETE | `/api/expenses/{id}` | delete |

The first controller wrote request fields directly, with no Form Request validation. `update` used `$request->all()`.

Categories, incomes, budgets, and transactions migrations existed but only had `id` + timestamps (except expenses).

---

## Phase 2 — Relational foundation (12 May 2026)

This is the current codebase.

### Database schema

**categories**

- `name` (unique), `type` (`income` / `expense`)
- index on `type`

**incomes**

- `user_id` → users, cascade delete
- `amount` (decimal 10,2), `source`, `income_date`
- indexes on `user_id`, `income_date`

**expenses** (rewritten)

- `user_id` → users, cascade delete
- `category_id` → categories, cascade delete
- `amount`, `description`, `expense_date`
- indexes on `user_id`, `category_id`, `expense_date`
- **`title` and string `category` were removed from the table**

**budgets**

- `user_id`, `category_id`
- `limit_amount`, `month_year` (date)
- indexes on those FKs and `month_year`

**transactions**

- Placeholder table was created in phase 1, then dropped by `2026_05_09_131005_drop_transactions_table`. The `Transaction` model file is still in the repo.

### Validation

`StoreExpenseRequest` and `UpdateExpenseRequest` now wrap create/update:

- store: `title` required, `amount` required numeric ≥ 0, `category_id` must exist, `expense_date` required date, `description` optional
- update: same fields with `sometimes`

The controller uses `$request->validated()` instead of mass-assigning the raw request.

### Seed data

`CategorySeeder` is wired from `DatabaseSeeder` and inserts:

- Expense: Food, Transport, Shopping, Entertainment, Bills
- Income: Salary, Freelance

### Factories

`ExpenseFactory` and `IncomeFactory` were added but `definition()` is empty. They are not used by seeders or tests.

---

## What works conceptually vs what is incomplete

The **intended** domain is: a user has expenses and incomes, grouped by categories, with monthly per-category budgets.

What is actually implemented end-to-end is only **expense HTTP CRUD**, and even that is out of sync with the new schema.

### Known gaps / mismatches (as of this review)

1. **Expense model vs expenses table**  
   Fillable still has `title` and `category`. The table has `user_id`, `category_id`, `amount`, `description`, `expense_date`. Creating an expense from the API will not persist `user_id`, and `title` is validated but not a column.

2. **No Eloquent relationships**  
   Models do not define `belongsTo` / `hasMany` between User, Category, Expense, Income, Budget.

3. **No auth**  
   Routes are public. Expenses are listed with `Expense::all()` (no user scoping). Users table exists from Laravel defaults only.

4. **Income, category, budget APIs**  
   No controllers or routes yet.

5. **Route model binding on update**  
   Route is `/expenses/{id}` but `update()` type-hints `Expense $expense`. Implicit binding matches the parameter name, so `{id}` likely will not resolve the model.

6. **Tests**  
   Only the default “GET `/` returns 200” feature test. No API tests. `RefreshDatabase` is commented out in `tests/Pest.php`.

7. **README**  
   Still the Laravel skeleton README.

---

## Current file map (product code)

```
app/
  Http/Controllers/ExpenseController.php
  Http/Requests/StoreExpenseRequest.php
  Http/Requests/UpdateExpenseRequest.php
  Models/{User,Expense,Income,Category,Budget,Transaction}.php
database/
  migrations/  users + cache + jobs + categories + incomes + expenses + budgets + drop transactions
  seeders/     DatabaseSeeder, CategorySeeder
  factories/   UserFactory, ExpenseFactory (empty), IncomeFactory (empty)
routes/api.php  expense CRUD only
```

---

## Suggested next work (not done)

These are the natural follow-ons from the current state, not a commitment:

- Align Expense fillable, migrations, and validation (`user_id` / `category_id`, drop or restore `title`)
- Add relationships and Form Requests consistently
- Auth (e.g. Sanctum) and scope all money data to the authenticated user
- CRUD for incomes, categories, budgets
- Pest feature tests for the API
- Fill factories and seed sample users/expenses
- Replace README with SpendSense setup and endpoint docs

---

## How to keep this file

When a meaningful chunk of work lands, add a new dated section under **Timeline** and a short “what changed / what is still open” note. Keep mismatches listed until they are fixed so this stays a source of truth rather than a changelog dump.
