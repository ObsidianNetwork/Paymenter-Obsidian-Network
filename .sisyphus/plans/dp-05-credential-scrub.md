# dp-05 Credential Scrub — Amend before push

**Status:** pending execution
**Parent plan:** `dp-05-admin-api-routes.md`
**Branch:** `dp-05-admin-api-routes` (local only, not yet pushed)
**Offending commit:** `a0af79a feat(admin-api): implement admin reservation and capacity endpoints`

## Problem

The dp-05 implementation commit `a0af79a` introduced hardcoded MariaDB credentials in `phpunit.xml`:

```xml
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="3306"/>
<env name="DB_DATABASE" value="paymenter_test"/>
<env name="DB_USERNAME" value="paymenter"/>
<env name="DB_PASSWORD" value="[REDACTED-MARIADB-PASSWORD]"/>
```

The original `DB_PASSWORD` value (redacted in this committed copy of the plan) is a real credential and must not enter the git history, even pre-push on a private repo. Once pushed, it's public-ish regardless of repo visibility (cached in forks, CI logs, `git log` leaks to future contributors). The literal value lived in the offending commit's `phpunit.xml`; that commit was amended away before push (see Acceptance below).

## Root cause

The subagent switched from `:memory:` SQLite to MariaDB because `pdo_sqlite` isn't installed for PHP 8.3 CLI on this host. It copied the working credentials verbatim from the outer Paymenter `.env` into `phpunit.xml` rather than relying on env inheritance.

## Fix (amend the existing commit — no push yet)

### Scope: 1 file, 4 lines removed

Edit `extensions/Others/DynamicPterodactyl/phpunit.xml`, remove lines 31, 32, 34, 35 (the `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` env elements). Keep only:

```xml
<env name="APP_ENV" value="testing"/>
<env name="DB_CONNECTION" value="mariadb"/>
<env name="DB_DATABASE" value="paymenter_test"/>
```

### Why this works

- `phpunit.xml` `<env>` tags override values from `.env` only for the vars they explicitly set.
- The outer Paymenter `.env` already has `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` populated for the local MariaDB instance.
- Tests only need the database name overridden (`paymenter_test`) so they don't trash production data.
- Credentials stay in `.env` (which is gitignored), never entering VCS.

### Verification sequence

After the edit:
1. `vendor/bin/phpunit --filter AdminApiTest` must still pass (7 tests).
2. `vendor/bin/phpunit` full suite must still pass (64 tests).
3. `git diff HEAD -- phpunit.xml` before amend should show the removal.

### Commit step

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git add phpunit.xml
git commit --amend --no-edit
git log -1 --stat  # confirm phpunit.xml only changed the 3 env lines now
```

Do NOT push. Parent agent verifies the amended commit then pushes + opens PR #4.

## Acceptance

- [ ] `phpunit.xml` has no `DB_PASSWORD` or `DB_USERNAME` lines
- [ ] `git log -p HEAD -- phpunit.xml` shows only the allowed additions (`APP_ENV`, `DB_CONNECTION`, `DB_DATABASE`, and the Feature testsuite block)
- [ ] `git log -p HEAD -- extensions/Others/DynamicPterodactyl/phpunit.xml | grep -iE 'DB_PASSWORD|DB_USERNAME'` returns nothing
- [ ] `vendor/bin/phpunit` still reports `OK (64 tests, 121 assertions)`
- [ ] Commit SHA changes (expected — amend rewrites history; safe because unpushed)

## Out of scope

- Installing `pdo_sqlite` to revert to in-memory (requires sudo; operator may prefer MariaDB test DB).
- Adding `.env.testing.example` to the extension (can be a followup if contributors get confused about the `paymenter_test` DB requirement).
- Documenting test setup in README (followup — covered under dp-07 or later).

## Delegation

Execute via `task(category="quick", load_skills=[], run_in_background=false)` — this is a single-file, 4-line mechanical edit + test run + amend. `quick` category is correct; no deep analysis needed.
