# Category ownership

Categories are now split into two visibility classes:

- **Shared defaults**: `user_id = NULL`, `is_default = true`. Every account sees
  the same rows. They cannot be renamed, retyped, or deleted through the API.
- **Private categories**: `user_id = <owner>`, `is_default = false`. They are
  visible only to their owner and can be used by that owner's expenses, budgets,
  recurring schedules, subscriptions, CSV imports, search filters, and reports.

Category references are validated against that visibility rule on create and
update. An update may keep an existing historical category attached to the same
record, but it cannot switch to another account's private category.

## Default set

The canonical list lives in `App\Support\DefaultCategories` and is seeded by
`Database\Seeders\CategorySeeder`. The migration adds missing defaults, and the
seeder is safe to run repeatedly. Production migration workflow runs the seeder
after migrations.

## Legacy rows and production preflight

The old schema had no owner or creator column, so the migration does **not**
guess ownership for existing non-default categories. Known default names/types
are promoted to shared defaults. Every other pre-existing row is preserved as:

```
user_id = NULL
is_default = false
```

Those legacy rows remain attached to historical records, but are hidden from
category selection and management. Before applying this migration to a
production snapshot, check for a pre-existing category whose name matches a
default but whose type is different (for example, a legacy `Food` category of
type `income`). The migration fails closed before changing the schema if it
finds one, because inserting the canonical default would otherwise collide with
the old globally unique name. Rename or explicitly map that category first.

Restore a recent production snapshot into an isolated database, run the
read-only preflight against the old schema, then run the migration and audit:

```bash
# Fails on default-name/type conflicts and on unreviewed legacy rows.
php artisan categories:audit-preflight

# Only after reviewing every legacy row and accepting its historical/read-only
# status, acknowledge it. This does not assign an owner or change any row.
php artisan categories:audit-preflight --allow-legacy

php artisan migrate --force
php artisan categories:audit-ownership
# Use --strict when no legacy rows should remain.
php artisan categories:audit-ownership --strict
```

The production migration workflow runs the same preflight before applying
migrations. A non-zero result stops the workflow before schema changes.
`--strict` exits non-zero when legacy rows need review; the workflow uses
non-strict mode only when `legacy_categories_reviewed=YES` was explicitly
selected. If the report shows legacy rows:

1. Stop before applying the production migration.
2. Export/snapshot the category and reference data.
3. Decide an explicit owner for each legacy category, or decide to leave it
   historical and read-only.
4. Apply any explicit, reviewed mapping separately. Do not infer ownership from
   transaction users automatically.
5. Re-run the audit and confirm the expected result.

## Rollback

The `down` migration would collapse categories back into the old global
namespace. Because that schema has no owner column, rollback fails closed while
any private category exists: otherwise those rows would become visible to every
account. It also fails closed if two categories share a name, because the old
schema required globally unique names. It does not rename, merge, or delete
anything. Resolve private categories and duplicate names explicitly before
rolling back.

SQLite rollback is covered by the migration test path used locally; PostgreSQL
uses normal foreign-key and unique-index drops.

## Deployment order

Deploy and migrate the backend first. The category UI treats a missing
`is_default` flag as shared/read-only, so an older backend cannot expose global
categories for editing while the new UI is live. Do not deploy a frontend that
assumes `is_default: false` before the backend returns that flag.

## Production deployment checklist

- Run `categories:audit-preflight` against a recent production snapshot and
  review every legacy row before approving the migration.
- Take a database backup and confirm the restore procedure.
- Apply migrations through the protected `Production Migration` workflow only
  after approval.
- Confirm the seeder step created/updated the canonical defaults.
- The post-migration audit runs in strict mode unless
  `legacy_categories_reviewed=YES` is selected. Only select `YES` after every
  legacy row has been reviewed and explicitly accepted as historical/read-only;
  otherwise a non-zero audit must stop the release.
- Verify category list, create, edit, delete, expense, budget, recurring, and
  subscription flows as an owner and as a second account.

## CI approval controls

- The frontend deployment and production-migration jobs both reference the
  protected `production` GitHub Environment. Configure required reviewers there
  before the first deployment.
- The production migration also requires an explicit `confirm=YES` dispatch
  input, a separate `legacy_categories_reviewed` acknowledgement for ambiguous
  legacy rows, validates the target Neon host/database/port before connecting,
  and runs the read-only category preflight before applying schema changes.
- The daily scheduled-job workflow intentionally runs without a manual
  environment approval because it must be unattended. Protect its production
  database secrets at the repository/environment level and keep the schedule on
  the canonical repository only.
