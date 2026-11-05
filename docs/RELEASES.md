# SpendSense Backend â€” Release & Versioning Guide

This document defines the semantic versioning strategy, release management process, cross-repository coordination, and hotfix workflows for the **SpendSense Backend** API.

---

## 1. Versioning Strategy

SpendSense follows [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html) (`vMAJOR.MINOR.PATCH`):

| Component | Format | When to Increment | Compatibility |
|---|---|---|---|
| **MAJOR** | `v1.0.0` | Incompatible API changes, breaking route/payload modifications, dropped database support, or breaking schema migrations. | Breaking |
| **MINOR** | `v0.2.0` | Backward-compatible new domain features, new API endpoints, non-breaking schema additions, or new domain services. | Backward-compatible |
| **PATCH** | `v0.2.1` | Backward-compatible bug fixes, security patches, calculation corrections, test hardening, or internal refactors. | Backward-compatible |

### Current Release Status: `v0.2.0` (Active Baseline)

* **`v0.1.0` (Initial Implementation Baseline):** Completed Stages 1-8 domain services, Sanctum authentication, initial migrations, soft deletion safety, and SQLite/PostgreSQL test configuration.
* **`v0.2.0` (Current Governance & Production Readiness Baseline):** Hardened budget alert month-boundary calculations, resolved CVE-2026-118516 Symfony advisories, established comprehensive architecture/API documentation, contributor governance, and structured release processes.

---

## 2. Cross-Repository Release Coordination

The SpendSense system is comprised of two distinct repositories:

1. **Backend:** [`ThunderSkull7/spendsense-backend`](https://github.com/ThunderSkull7/spendsense-backend) (Laravel REST API hosted on Render with PostgreSQL on Neon)
2. **Frontend:** [`ThunderSkull7/spendsense-frontend`](https://github.com/ThunderSkull7/spendsense-frontend) (Static vanilla JS application hosted on Cloudflare Pages with Pages Worker reverse proxy)

### Compatibility Matrix

| Backend Version | Compatible Frontend Version | Contract Notes |
|---|---|---|
| `v0.2.x` | `v0.2.x` | Stateful Sanctum session authentication, `/api/*` endpoints, `/user` hydration route, and standard error envelopes (`message`, `errors`). |
| `v0.1.x` | `v0.1.x` | Initial prototype endpoints and legacy reporting payloads. |

### Release Sequencing Rules

When releasing changes that span both repositories:

1. **Deploy and tag the Backend first:** New endpoints, fields, or migrations must be deployed and verified on Render/PostgreSQL before frontend code consuming them is deployed to Cloudflare Pages.
2. **Backward-compatible API expansions:** When introducing new UI capabilities, backend endpoints should support optional parameters or defaults so older frontend sessions continue to function without errors.
3. **Breaking changes (MAJOR release):** If a breaking API contract modification is unavoidable, communicate the migration plan in advance, coordinate a maintenance window, and deploy the backend before cutting the corresponding frontend major release.

---

## 3. Creating Annotated Git Tags

All formal releases are marked by signed or annotated Git tags following the `vX.Y.Z` naming convention:

```bash
# 1. Ensure you are on the latest main branch
git checkout main
git pull --ff-only origin main

# 2. Verify that all CI checks are green
gh run list --branch main --limit 3

# 3. Create an annotated Git tag with a release summary
git tag -a v0.2.0 -m "Release v0.2.0: Architecture documentation, security remediation, and contributor governance"

# 4. Push the tag to GitHub (only when authorized)
git push origin v0.2.0
```

> [!CAUTION]
> **Do not push release tags without explicit review and authorization.**

---

## 4. Publishing GitHub Releases

GitHub Releases provide release notes, artifact links, and commit changelogs for consumers and contributors.

### Publishing via GitHub CLI (`gh`)

```bash
# Generate release notes from tag
gh release create v0.2.0 \
  --title "SpendSense Backend v0.2.0 â€” Production Readiness & Governance" \
  --notes-file docs/release-notes-v0.2.0.md \
  --verify-tag
```

### Release Notes Template

```markdown
## Highlights
- Brief summary of the most impactful changes in this release.

## What's Changed
### Added
- Feature 1 or new endpoint
- Feature 2 or new documentation

### Changed
- Performance improvement or refactored service

### Fixed
- Bug fix or calculation edge-case

### Security
- Vulnerability remediation or dependency updates

## Cross-Repository Compatibility
- Compatible with `spendsense-frontend` version: `v0.2.x`

**Full Commit Changelog:** https://github.com/ThunderSkull7/spendsense-backend/compare/v0.1.0...v0.2.0
```

---

## 5. Hotfix Procedure

When a critical bug or security flaw is discovered in production:

1. **Branch from the current release tag:**
   ```bash
   git checkout -b fix/critical-bug v0.2.0
   ```
2. **Apply the minimal fix:** Keep the change isolated to the critical defect.
3. **Add regression tests:** Verify with Pest (`php vendor/bin/pest`) to prevent future regressions.
4. **Submit PR targeting `main`:** Ensure CI passes across SQLite and PostgreSQL.
5. **Merge and tag PATCH release:** Tag `v0.2.1` and publish release notes detailing the remediation.
