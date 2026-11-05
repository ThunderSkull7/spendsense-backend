# SpendSense Backend - Release & Versioning Guide

This document defines the semantic versioning strategy, release management process, cross-repository coordination, and hotfix workflows for the **SpendSense Backend** API.

---

## 1. Versioning Strategy

SpendSense adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html) (`vMAJOR.MINOR.PATCH`).

### Semantic Versioning Specification (Pre-1.0 Behavior)

Under **SemVer 2.0.0 Clause 4**:
> *"Major version zero (0.y.z) is for initial development. Anything MAY change at any time. The public API SHOULD NOT be considered stable."*

Accordingly, pre-1.0 versions do not provide strict contractual guarantees against breaking changes between arbitrary minor versions.

### SpendSense Compatibility Policy

To provide stability and predictability across frontend and backend integrations, the SpendSense project voluntarily adopts the following internal release conventions:

| Component | Format | When to Increment | Project Policy |
|---|---|---|---|
| **MAJOR** | `v1.0.0` | Formal public production release with finalized, stable public API contracts and schemas. | Breaking |
| **MINOR** | `v0.2.0` | Backward-compatible new domain features, new API endpoints, non-breaking schema additions, or new domain services. | Planned backward-compatible |
| **PATCH** | `v0.2.1` | Backward-compatible bug fixes, security patches, calculation corrections, test hardening, or internal refactors. | Backward-compatible |

### Current Repository Status

* **Historical Baseline (`0.1.0`):** Initial historical development baseline commit (`a4b14e7`) covering Stages 1-8 domain services, Sanctum authentication, initial migrations, soft deletion safety, and SQLite/PostgreSQL test configuration.
* **Active Working State (`[Unreleased]`):** Hardened budget alert month-boundary calculations, updated Symfony component dependencies (resolving Composer security advisories), established comprehensive architecture/API documentation, contributor governance, and structured release processes.
* **Release Tags:** No Git release tags or GitHub Releases have been published yet. Formal tagging will commence when authorized.

---

## 2. Cross-Repository Release Coordination

The SpendSense system is comprised of two distinct repositories:

1. **Backend:** [`ThunderSkull7/spendsense-backend`](https://github.com/ThunderSkull7/spendsense-backend) (Laravel REST API hosted on Render with PostgreSQL on Neon)
2. **Frontend:** [`ThunderSkull7/spendsense-frontend`](https://github.com/ThunderSkull7/spendsense-frontend) (Static vanilla JS application hosted on Cloudflare Pages with Pages Worker reverse proxy)

### Compatibility Matrix

| Backend Ref / Branch | Frontend Ref / Branch | Compatibility Status | Integration Contract Notes |
|---|---|---|---|
| `main` branch (Active) | `main` branch (Active) | :white_check_mark: Verified Compatible | Currently verified compatible pairing: stateful Sanctum session cookies, `/api/*` routes, `/user` hydration route, and same-origin Pages Worker reverse proxy. |
| Historical baseline commit `a4b14e7` | Historical baseline commit `2586369` | :warning: Unverified (Historical Baseline) | Internal development baselines representing initial pre-release implementation. Their existence does not establish verified end-to-end cross-repository compatibility. |
| Arbitrary historical or cross-version pairings | Arbitrary historical or cross-version pairings | :warning: Unverified | Pre-1.0 versions do not formalize cross-version compatibility. Arbitrary historical or mismatched combinations remain unverified unless supported by explicit integration-test evidence. |

### Release Sequencing Rules

When releasing changes that span both repositories:

1. **Deploy and verify Backend first:** New endpoints, parameters, or database migrations must be deployed and verified on Render/PostgreSQL before frontend code consuming them is deployed to Cloudflare Pages.
2. **Backward-compatible API expansions:** When introducing new UI capabilities, backend endpoints should support optional parameters or defaults so existing frontend sessions continue to function without errors.
3. **Breaking changes:** When breaking API modifications are required during active development, coordinate deployment windows and update both repositories in lockstep.

---

## 3. Creating Annotated Git Tags (Future-Facing Guide)

When a formal release is authorized, annotated Git tags should be created using the `vX.Y.Z` naming convention:

```bash
# 1. Ensure you are on the latest main branch
git checkout main
git pull --ff-only origin main

# 2. Verify that all CI checks are green
gh run list --branch main --limit 3

# 3. Create an annotated Git tag with a release summary (illustrative example)
# NOTE: Do not execute until an official release is authorized.
git tag -a v0.2.0 -m "Release v0.2.0: Architecture documentation, security remediation, and contributor governance"

# 4. Push the tag to GitHub (only when explicitly authorized)
git push origin v0.2.0
```

> [!CAUTION]
> **Do not create or push release tags without explicit review and authorization.**

---

## 4. Publishing GitHub Releases (Future-Facing Guide)

GitHub Releases provide release notes, artifact links, and commit changelogs for consumers and contributors.

### Publishing via GitHub CLI (`gh`)

```bash
# Illustrative command for future release publication
gh release create v0.2.0 \
  --title "SpendSense Backend v0.2.0 - Production Readiness & Governance" \
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
- Verified compatible with `spendsense-frontend` reference: [specify commit/tag]

*(Full commit comparison link will be available once formal release tags are published.)*
```

---

## 5. Hotfix Procedure

When a critical bug or security flaw is discovered in production:

1. **Branch from the current stable commit or tag:**
   ```bash
   git checkout -b fix/critical-bug main
   ```
2. **Apply the minimal fix:** Keep the change isolated to the critical defect.
3. **Add regression tests:** Verify with Pest (`composer test` / `php vendor/bin/pest`) to prevent regressions.
4. **Submit PR targeting `main`:** Ensure CI passes across SQLite, PostgreSQL, and Composer audit.
5. **Merge and tag PATCH release (when authorized):** Tag `v0.2.1` and publish release notes detailing the remediation.
