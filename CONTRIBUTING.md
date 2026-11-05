# Contributing to SpendSense Backend

Thank you for your interest in contributing to the **SpendSense Backend**! This repository hosts the Laravel 12 REST API providing authentication, financial domain operations, database integrity, and reporting services for SpendSense.

This guide outlines our development workflow, standards, testing expectations, and contribution process.

---

## Code of Conduct

All contributors and participants are expected to adhere to our [Code of Conduct](CODE_OF_CONDUCT.md). Please read it to understand our community standards and expectations.

---

## Reporting Issues & Requesting Features

- **Bug Reports:** If you discover a bug, unexpected API response, or calculation issue, please open an issue using the [Bug Report template](https://github.com/ThunderSkull7/spendsense-backend/issues/new?template=bug_report.yml).
- **Feature Requests:** To propose new endpoints, domain capabilities, or performance enhancements, open an issue using the [Feature Request template](https://github.com/ThunderSkull7/spendsense-backend/issues/new?template=feature_request.yml).
- **Security Vulnerabilities:** **Do not open public GitHub issues for security vulnerabilities.** Please review our [Security Policy](SECURITY.md) for instructions on confidential disclosure.

---

## Getting Started

### 1. Prerequisites

Ensure your local development environment meets the following requirements:

- **PHP:** `^8.2` or `^8.3` (matching CI runtime)
- **PHP Extensions:** `pdo_sqlite`, `pdo_pgsql`, `mbstring`, `bcmath`, `xml`, `curl`, `zip`
- **Composer:** v2.5 or later
- **Database:** SQLite (default for development and unit tests) or PostgreSQL 16+ (for production parity and constraint verification)
- **Git:** v2.30 or later

### 2. Fork and Clone

1. Fork the repository to your GitHub account.
2. Clone your fork locally:
   ```bash
   git clone https://github.com/<your-username>/spendsense-backend.git
   cd spendsense-backend
   ```
3. Add the upstream repository as a remote:
   ```bash
   git remote add upstream https://github.com/ThunderSkull7/spendsense-backend.git
   git fetch upstream
   ```

### 3. Local Environment Setup

1. Copy the example environment file:
   ```bash
   cp .env.example .env
   ```
2. Install PHP dependencies:
   ```bash
   composer install
   ```
3. Generate the application encryption key:
   ```bash
   php artisan key:generate
   ```
4. Run database migrations with sample structure:
   ```bash
   php artisan migrate
   ```
5. Start the local API development server:
   ```bash
   php artisan serve --port=8002
   ```
   The backend API will now be accessible at `http://127.0.0.1:8002`.

---

## Branching Conventions

Always create a dedicated feature branch from the latest upstream `main` branch:

```bash
git checkout main
git pull upstream main
git checkout -b <type>/<descriptive-kebab-case-name>
```

### Branch Prefix Types

- `feat/`: New endpoint, service capability, or feature (e.g., `feat/budget-rollover`)
- `fix/`: Bug fix or edge-case correction (e.g., `fix/sqlite-date-boundary`)
- `docs/`: Documentation, diagrams, or governance updates (e.g., `docs/api-contracts`)
- `test/`: New automated test cases or test infrastructure improvements (e.g., `test/idempotency-concurrency`)
- `refactor/`: Code reorganization without functional or behavioral changes (e.g., `refactor/report-service`)
- `chore/`: Dependency upgrades, tool configuration, or CI tweaks (e.g., `chore/composer-bump`)

---

## Commit Message Guidelines

We follow the [Conventional Commits](https://www.conventionalcommits.org/) specification:

```
<type>(<scope>): <short description in imperative present tense>

[optional body explaining motivation and context]

[optional footer with issue reference, e.g., Closes #12]
```

### Examples

- `feat(budgets): add threshold alert notifications for monthly spending`
- `fix(reporting): resolve month-boundary transaction inclusion in sqlite`
- `docs(database): update schema diagram for category ownership indexes`
- `test(expenses): add test coverage for soft deletion and restoration`
- `refactor(auth): simplify sanctum token validation flow`

Keep commits focused, atomic, and logically grouped. Avoid broad catch-all commits.

---

## Code Quality & Engineering Standards

### 1. Code Style

We follow the PSR-12 coding standard enforced by **Laravel Pint**:

```bash
# Check code style without modifying files
vendor/bin/pint --test

# Automatically fix code style violations
vendor/bin/pint
```

### 2. Architecture & Design Principles

- **Controller Responsibilities:** Controllers handle HTTP routing, invoke FormRequests for input validation, and delegate business logic to dedicated services (`app/Services/`). Avoid placing business calculations directly in controllers.
- **Database Integrity:** Database constraints, foreign keys, and transactions are authoritative. Enforce business rules at both the application level and the database level.
- **Explicit Type Hinting:** Use strict type hints and return type declarations on all new methods and functions.
- **Idempotency:** Where write operations require deduplication, adhere to the `IdempotencyService` pattern using the `Idempotency-Key` header.
- **Documentation Parity:** If an endpoint or schema changes, update the corresponding documentation in `docs/API.md`, `docs/ARCHITECTURE.md`, or `docs/DATABASE.md`.

---

## Testing Guidelines

SpendSense uses **Pest** (built on PHPUnit) for automated unit and feature testing.

### Running Tests Locally

1. **Run the standard test suite (in-memory SQLite):**
   ```bash
   composer test
   # or
   php vendor/bin/pest
   ```

2. **Run a specific test file or group:**
   ```bash
   php vendor/bin/pest tests/Feature/BudgetAlertTest.php
   php vendor/bin/pest --filter="budget alert"
   ```

3. **Run dependency vulnerability audits:**
   ```bash
   composer audit
   ```

4. **Smoke-test scheduled commands:**
   ```bash
   php artisan model:prune --model='App\Models\IdempotencyRecord'
   php artisan recurring-expenses:sync-reminders
   ```

5. **PostgreSQL testing (optional locally, runs in CI):**
   If you have a local PostgreSQL instance running:
   ```bash
   php artisan migrate --force --database=pgsql
   DB_CONNECTION=pgsql DB_DATABASE=spendsense_test php vendor/bin/pest
   ```

> [!NOTE]
> All Pull Requests must pass the full SQLite test suite, PostgreSQL test suite, code style check (`vendor/bin/pint --test`), and dependency audit in CI before being merged.

---

## Submitting a Pull Request

1. **Verify all checks locally:**
   - [ ] All tests pass: `composer test`
   - [ ] Code style matches Pint: `vendor/bin/pint --test`
   - [ ] Dependency audit passes: `composer audit`
2. **Push your branch to your fork:**
   ```bash
   git push origin <type>/<descriptive-name>
   ```
3. **Open a Pull Request:**
   - Target the `main` branch of `ThunderSkull7/spendsense-backend`.
   - Provide a clear, detailed PR title using Conventional Commits syntax.
   - Complete all sections of the [Pull Request template](.github/pull_request_template.md).
   - Link any related issue(s) (e.g., `Fixes #42`).
4. **Address Review Feedback:**
   - Respond to review comments constructively.
   - Push additional commits to your branch to address requested revisions.

Thank you for helping make SpendSense reliable, secure, and maintainable!
