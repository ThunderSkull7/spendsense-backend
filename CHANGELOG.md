# Changelog

All notable changes to the SpendSense Backend will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [0.2.0] - 2026-11-05

### Added
- **Contributor Experience & Governance Infrastructure:**
  - Added practical [CONTRIBUTING.md](CONTRIBUTING.md) guide detailing development workflow, Pest test commands, Pint formatting, and branch/commit conventions.
  - Added responsible disclosure [SECURITY.md](SECURITY.md) policy with confidential reporting pathways.
  - Added [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) based on Contributor Covenant v2.1 with clear maintenance contact routing.
  - Added structured GitHub Issue Forms for bug reports (`bug_report.yml`) and feature requests (`feature_request.yml`).
  - Added standardized [Pull Request template](.github/pull_request_template.md).
- **Comprehensive Technical Documentation:**
  - Added [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) covering application lifecycle, service layer, request validation, and domain patterns.
  - Added [docs/API.md](docs/API.md) documenting authentication, error formats, query parameters, and REST endpoint contracts.
  - Added [docs/DATABASE.md](docs/DATABASE.md) detailing schema structure, relational constraints, and composite index design.
  - Added [docs/AUTHENTICATION.md](docs/AUTHENTICATION.md) detailing Laravel Sanctum cookie-based session hydration and CSRF handling.
  - Added [docs/RELEASES.md](docs/RELEASES.md) establishing semantic versioning rules, tagging guidelines, and cross-repository release coordination.
- **Category Ownership & Profile Management:**
  - Implemented category ownership boundaries separating shared system defaults from user-private categories (PR #1, PR #2).
  - Added authenticated profile management endpoints (`GET /user`, `PUT /user/profile`).
- **Visual Application Showcase:**
  - Integrated rich application preview table in `README.md` showcasing desktop dashboard, expense ledger, and reporting interfaces.

### Changed
- Refined production API documentation to designate the browser-facing Cloudflare Pages Worker origin (`https://spendsensehq.pages.dev/api`) as the authoritative client contract, clarifying the reverse proxy architecture.
- Refined database index descriptions to accurately characterize query optimization without unmeasured performance guarantees.
- Restructured `README.md` to reflect verified system requirements, live service links, and accurate test execution commands.

### Fixed
- **Budget Alert Month-Boundary Calculation:** Resolved failure in `StageFiveBoundaryTest` by pinning the test clock and correcting date boundary rules to properly include transactions dated on the final day of a calendar month in threshold alert totals (PR #4).
- Added regression tests for month transitions and adjacent boundary isolation.

### Security
- **Dependency Remediation:** Updated Symfony component dependencies to remediate CVE-2026-118516 and associated vendor advisories (PR #4).
- Verified clean dependency audit via `composer audit` (0 known advisories).

---

## [0.1.0] - 2026-10-24

### Added
- **Core Domain Services & Architecture:**
  - Laravel 12 API foundation with service-oriented architecture (`app/Services/`).
  - Implemented domain services for expenses, incomes, recurring schedules, budgets, savings goals, notifications, reports, and CSV data imports.
- **Database & Data Integrity:**
  - Initial relational schema with foreign key constraints, cascading rules, and check constraints for PostgreSQL and SQLite.
  - Soft-deletion safety and restoration mechanisms for income and expense records.
  - Composite reporting indexes on financial transaction tables.
- **Reliability & Idempotency:**
  - Added `idempotent` middleware and `IdempotencyRecord` persistence to safely deduplicate mutation retries using the `Idempotency-Key` HTTP header.
- **Authentication & Security:**
  - Integrated Laravel Sanctum for first-party cookie session authentication and CSRF token verification.
- **Automated Testing Suite:**
  - Established Pest automated test suite with unit and feature test coverage across SQLite in-memory and PostgreSQL.
  - Added GitHub Actions CI pipeline running SQLite tests, PostgreSQL integration tests, and scheduled jobs.
- **Containerization & Deployment:**
  - Added production Dockerfile and Render hosting configuration.
