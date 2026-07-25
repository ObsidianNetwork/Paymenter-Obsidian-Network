# dp-13 — SetupWizard Atomicity + Audit-Log Reliability + Test Isolation Hygiene

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (the `dynamic-slider` branch in the extension repo at `https://github.com/Jordanmuss99/dynamic-pterodactyl.git`). Extension-only; no core changes.
**Type**: Correctness + E2E-coverage + incident-prevention patch series. Same shape as dp-11.
**Delivery**: Single PR, atomic commit per concern, squash-merge.
**Backlog mapping**: Fulfils the "dp-13: SetupWizard atomicity + audit-log reliability + E2E test" backlog item recorded in PROGRESS.md and explicitly deferred from dp-07 (Decision #5), dp-08 ("Audit-log reliability / `safeAudit` swallowed failures — dp-13", `.sisyphus/plans/dp-08-reservation-verification.md:311`), dp-09, dp-10, and dp-11.
**Also absorbs**: the incident-prevention rule from `.sisyphus/notepads/dp-11-authorization-surface-reduction/incidents.md:36` (test isolation mandate).

---

## Problem

Four concrete gaps, verified by recon:

### 1. `ConfigOptionSetupService::createDynamicSliderOptions()` is not atomic (`Services/ConfigOptionSetupService.php:45-78`)

The wizard creates 3 resource sliders (memory/cpu/disk) + 1 location option (with up to N child options) + 1 audit entry across **at least 4 separate DB writes** (`ConfigOption::create()` per resource, each followed by `products()->syncWithoutDetaching()`, then `ConfigOption::create()` for the location parent, then `updateOrCreate()` for each child location, then the audit row). None of this is wrapped in a transaction.

**Failure mode**: if memory+cpu succeed but disk fails (e.g., transient DB hiccup, validation throw from `DynamicSliderPricingRule`, or broken metadata), memory+cpu are already persisted as orphans — no rollback, no signal to the operator, and the wizard's "existing options detected" branch on the next run will pick up the partial state and mis-repair it. **Severity: High** — data corruption in a single admin flow.

### 2. `safeAudit` pattern is duplicated/inconsistent (`Services/ReservationService.php:38-45` vs. `Services/ConfigOptionSetupService.php:66-74`)

`ReservationService` has a clean `private function safeAudit()` wrapper that `report($e)`s on failure. `ConfigOptionSetupService` open-codes its own `try { $this->audit->log(...) } catch { report($e) }` inline at line 66. Two different failure envelopes for the same concern.

Separately, **audit failures are invisible** in production unless Sentry/similar is wired. `report($e)` just hands the exception to the Laravel exception handler — if the ignition/bugsnag/sentry reporter isn't configured, the audit loss is silent. No structured log, no counter, no alert. **Severity: Medium** — silent observability gap.

### 3. SetupWizard has no Filament-action-lifecycle E2E test (`tests/Feature/SetupWizardValidationTest.php:17-21`)

dp-07 Decision #5 explicitly parked this:
> Unit coverage accepted for dp-06. The full Filament-action lifecycle end-to-end test is deferred to **dp-13**.

The current placeholder is:
```php
// TODO dp-13: implement full Filament action lifecycle E2E test for SetupWizard pricing validation
$this->markTestSkipped(...);
```

Every dp-NN since has skipped this placeholder. dp-13 owns it because dp-13 already has to touch `ConfigOptionSetupService` for gap #1 — the E2E test naturally validates both the atomicity patch AND the original wizard flow. **Severity: Medium** — regression surface gap.

### 4. Extension `phpunit.xml` is missing test-isolation env overrides (`phpunit.xml:27-32` vs. `/var/www/paymenter/phpunit.xml:24-35`)

The extension's `phpunit.xml` sets only `APP_ENV`, `DB_CONNECTION`, `DB_DATABASE`. The **root** `phpunit.xml` additionally sets:

- `CACHE_STORE=array`
- `CACHE_DRIVER=file`
- `SESSION_DRIVER=array`
- `QUEUE_CONNECTION=sync`
- `MAIL_MAILER=array`
- `BCRYPT_ROUNDS=4`
- `APP_MAINTENANCE_DRIVER=file`
- `PULSE_ENABLED=false`, `TELESCOPE_ENABLED=false`

**This is the direct cause of the 2026-04-23 cache-poisoning incident** documented in `.sisyphus/notepads/dp-11-authorization-surface-reduction/incidents.md`. Running the extension phpunit against a production host pollutes the shared Redis/file cache's `settings` key, which the running web workers then read as an empty Collection → `config('settings.theme')` returns empty → `@vite()` falls back to `public/build/` which doesn't exist → every page 500s.

**Severity: Critical** — any future extension-test run on a shared host reproduces the outage. Already happened once; next agent could repeat it trivially.

---

## Design

Five concerns, five commits. Each commit must keep the extension phpunit suite green (run it from the extension directory **after** commit 1 lands the isolation fix).

### Commit 1 — Test isolation hygiene (FIRST, incident-prevention)

**Files**:
- `phpunit.xml` (extension) — add the missing `<env>` overrides
- `tests/bootstrap.php` — add a runtime assertion that aborts if `DB_DATABASE` is not `paymenter_test` or `:memory:` (prevents the "wrong DB" failure mode independently of env-var setup)
- `CLAUDE.md` / `AGENTS.md` — document the mandate (one-liner referencing DECISIONS.md)

**Change**:

Update `phpunit.xml` `<php>` block to mirror the root phpunit.xml's full env set:

```xml
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="APP_MAINTENANCE_DRIVER" value="file"/>
    <env name="BCRYPT_ROUNDS" value="4"/>
    <env name="CACHE_STORE" value="array"/>
    <env name="CACHE_DRIVER" value="file"/>
    <env name="DB_CONNECTION" value="mariadb"/>
    <env name="DB_DATABASE" value="paymenter_test"/>
    <env name="MAIL_MAILER" value="array"/>
    <env name="QUEUE_CONNECTION" value="sync"/>
    <env name="SESSION_DRIVER" value="array"/>
    <env name="PULSE_ENABLED" value="false"/>
    <env name="TELESCOPE_ENABLED" value="false"/>
</php>
```

Add to `tests/bootstrap.php`:

```php
// dp-13 guard: refuse to boot against a non-test database.
$db = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? '');
if ($db !== 'paymenter_test' && $db !== ':memory:' && $db !== '') {
    fwrite(STDERR, "ABORT: phpunit would run against DB_DATABASE='$db'. "
        . "Expected 'paymenter_test' or ':memory:'. See .sisyphus/notepads/dp-11-…/incidents.md.\n");
    exit(2);
}
```

**Why first**: zero risk of another cache-poisoning incident during the subsequent commits in this PR. Must land before any test run that could touch the shared cache.

**Test additions**: none automated (this IS the test infra). Manual smoke: run the extension phpunit once and confirm the production `cache:get settings` key is untouched (check with `redis-cli` or `php artisan tinker`).

### Commit 2 — Atomicity: wrap SetupWizard creation in a transaction

**Files**:
- `Services/ConfigOptionSetupService.php` — wrap `createDynamicSliderOptions()` in `DB::transaction()`
- `tests/Unit/ConfigOptionSetupServiceTest.php` (new or extended) — test mid-batch failure triggers rollback

**Change**:

```php
public function createDynamicSliderOptions(int $productId, array $config, array $locations = []): array
{
    $created = DB::transaction(function () use ($productId, $config, $locations) {
        $out = [];
        foreach (['memory', 'cpu', 'disk'] as $resourceType) {
            $enableKey = "enable_{$resourceType}_slider";
            if (($config[$enableKey] ?? true) === false) continue;
            $out[$resourceType] = $this->createResourceOption($productId, $resourceType, $config);
        }
        if (! empty($locations)) {
            $out['location'] = $this->createLocationOption($productId, $locations);
        }
        return $out;
    });

    // Audit AFTER commit so a successful transaction is recorded even if audit fails.
    if (! empty($created)) {
        $this->safeAudit('setup_run', 'product_config', $productId, [
            'sliders_configured' => array_keys($created),
            'count' => count($created),
        ]);
    }

    return $created;
}
```

Where `safeAudit()` is the shared helper added in commit 3.

**Why second**: the atomicity fix is the headline business change. Landing before the audit refactor means the transaction boundary is committed to code even if the audit change runs into review pushback.

**Test additions**:
- `test_createDynamicSliderOptions_rolls_back_on_mid_batch_failure`: mock `DynamicSliderPricingRule` to throw on disk after memory+cpu succeed → assert `config_options` table has zero rows for that product.
- `test_createDynamicSliderOptions_happy_path_creates_all_four`: assert 3 sliders + 1 location + children all present.

### Commit 3 — Audit reliability: shared trait + warning log

**Files**:
- `Services/Concerns/AuditsExtensionActions.php` (new trait) — `protected function safeAudit(...)`
- `Services/ReservationService.php` — `use AuditsExtensionActions;`, delete private method
- `Services/ConfigOptionSetupService.php` — `use AuditsExtensionActions;`, replace inline try/catch at line 66 with `$this->safeAudit(...)`
- `tests/Unit/ReservationServiceTest.php` / `ConfigOptionSetupServiceTest.php` — regression tests

**Change**:

```php
trait AuditsExtensionActions
{
    protected function safeAudit(string $action, string $entityType, int $entityId, ?array $newValues = null): void
    {
        try {
            app(AuditLogService::class)->log($action, $entityType, $entityId, $newValues);
        } catch (\Throwable $e) {
            // Explicit structured warning so audit loss shows up in normal logs,
            // not just report() (which silently no-ops without a configured reporter).
            Log::warning('extension audit write failed', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
            report($e);
        }
    }
}
```

Single source of truth. Both services get the same failure envelope. **Audit failures now always produce a structured `warning` log** even if no exception reporter is wired — closes the silent-loss gap.

**Why third**: depends on commit 2's reference to `safeAudit` on `ConfigOptionSetupService`. Commit 3 makes that reference real.

**Test additions**:
- `test_safeAudit_logs_warning_on_failure`: mock the audit service to throw → assert `Log::warning` captured the `extension audit write failed` message with the expected context.
- `test_setup_run_audit_still_fires_on_successful_transaction`: happy path regression.

### Commit 4 — SetupWizard E2E test

**Files**:
- `tests/Feature/SetupWizardValidationTest.php` — replace the `markTestSkipped` stub with real Filament action lifecycle coverage
- Possibly factory helpers at `tests/Feature/Support/*` (if needed for user+product+plan fixture)

**Change**:

Implement the three test cases dp-07 promised:
1. `test_wizard_rejects_invalid_pricing_with_form_error`: Livewire test against the SetupWizard page, submit a config with `model: 'tiered'` and missing `tiers` → assert the Filament action halts and surfaces the expected error.
2. `test_wizard_creates_three_sliders_and_location_on_valid_submission`: submit valid config → assert `config_options` has 4 parent rows (memory, cpu, disk, location) + children, `config_option_products` pivot has all 4, and `ptero_audit_logs` has a `setup_run` row.
3. `test_wizard_rollback_on_validator_failure_mid_batch`: patch disk resource metadata to trigger pricing-rule throw → assert zero options persisted.

If Filament 4's Livewire test harness proves flaky for one of these in CI, fall back to driving `ConfigOptionSetupService` directly via a feature test and document the compromise.

**Why fourth**: depends on commits 2+3 being landed — the E2E test validates the new atomic + audited path, not the old unsafe one.

**Test additions**: the E2E suite itself. Remove the `markTestSkipped` and the `TODO dp-13` marker.

### Commit 5 — Docs + DECISIONS entries

**Files**:
- `DECISIONS.md` — new numbered decision: "Test isolation mandate (dp-13, Apr 2026)" explaining why extension phpunit MUST override cache/session/queue/mail drivers.
- `DECISIONS.md` — new numbered decision: "SetupWizard atomicity contract (dp-13)" — either all slider options + location + audit land, or none.
- `09-IMPLEMENTATION.md` — section on SetupWizard atomicity + audit-reliability invariants.
- `CHANGELOG.md` — `[Unreleased]` → `### Fixed` entry covering all three concerns.
- Remove the dp-07 `TODO dp-13:` annotation pointer since the work is now done (leave the decision record in DECISIONS.md, it's still the canonical explanation).

**No code changes.**

---

## Deferred (out of scope for dp-13)

- **Queue-based async audit** (write-behind queue with retry). Too big for this PR; the `Log::warning` fallback from commit 3 is sufficient for most ops. Defer to a new `dp-NN-audit-queue.md` if demand exists.
- **Hosting-provider snapshot policy / off-host backup automation** — the dp-11 incident add-on installed `/usr/local/bin/paymenter-db-backup.sh` and the systemd timer. Off-host sync (rclone → S3/B2) is documented in `/var/backups/paymenter/README` but enabling it is ops work, not code.
- **`AlertService::checkCapacityAlerts()` scheduler wiring** — dp-12's job.
- **Core-side test isolation** (Paymenter root phpunit.xml) — already correct. Nothing to do.
- **Proxmox snapshot retention automation** — ops concern, not extension code.
- **`AuditLogService` API hardening** (e.g., `->enqueue()` variant, JSON-schema validation on `new_values`) — would double this PR's size.
- **SetupWizard UX improvements** (confirm dialog, "dry-run" preview) — a separate UX plan.

---

## Testing

- After commit 1: run `cd extensions/Others/DynamicPterodactyl && ../../../vendor/bin/phpunit --configuration phpunit.xml`. Must stay green. **Also check production cache was NOT touched** by running `redis-cli GET settings` (or equivalent for your cache backend) before/after — value must be unchanged.
- After commits 2-4: same phpunit invocation. Must stay green with the new tests added in each commit.
- Final suite: 101+ tests (baseline from dp-11 was 101). Expect +3-5 new tests from commits 2/3/4.
- Manual smoke (post-merge):
  1. Log into admin panel, navigate to SetupWizard for a new product, submit a config with deliberately-broken `pricing.tiers` (empty array) → UI shows rejection notification, no options created (`SELECT COUNT(*) FROM config_options WHERE ...` returns 0).
  2. Submit valid config → 3 sliders + 1 location appear, audit log has `setup_run` row.
  3. Kill MariaDB briefly mid-wizard-submit (e.g., `systemctl stop mariadb` right before clicking Save, restart after error surfaces) → no partial rows in `config_options`.

---

## Risks

| Risk | Mitigation |
|---|---|
| Wrapping `createDynamicSliderOptions` in a transaction surfaces FK/ordering issues that previously hid behind implicit auto-commits | Commit 2 includes happy-path test; manual smoke covers the common cases. Any FK issue would already be failing silently today — surfacing it IS the benefit. |
| Moving audit call after commit means a single audit failure leaves options without an audit entry | Acceptable and documented in DECISIONS.md. Business logic wins; audit is best-effort (this is the existing policy, now explicit). Commit 3's `Log::warning` ensures the failure is visible. |
| Filament 4 Livewire test harness quirks break commit 4's E2E | Fallback: drive `ConfigOptionSetupService` directly via a feature test and document the compromise inline. Don't block the PR on E2E coverage if the harness is the obstacle. |
| Commit 1's new env overrides change test behaviour in unexpected ways (e.g., session or queue assumptions) | Run the full 101-test suite after commit 1 before any other work. If any test breaks because it relied on the shared cache/session, fix it in the same commit rather than defer. |
| `bootstrap.php` guard aborts an existing CI job that sets DB via a different env-var path | Guard uses `getenv()` + `$_ENV` fallback (what Laravel actually reads). Document override: setting `DB_DATABASE=paymenter_test` explicitly in the CI environment is already the convention. |
| Trait injection of `AuditLogService` via `app()` creates a testing inconvenience | Acceptable; alternative is constructor-injection which would require touching every caller. The `app()` lookup is test-friendly via `$this->app->instance(AuditLogService::class, $mock)`. |

---

## Acceptance

- Branch `dp-13-setupwizard-atomicity-audit-reliability` on the extension fork, PR opened against `dynamic-slider`.
- All five commits land; squash-merge as one PR.
- Extension `phpunit.xml` mirrors the root phpunit.xml's test-isolation env set.
- `tests/bootstrap.php` aborts if `DB_DATABASE` is not a recognized test DB.
- `ConfigOptionSetupService::createDynamicSliderOptions()` is wrapped in a single `DB::transaction()`.
- `safeAudit()` lives in a shared trait (`Services/Concerns/AuditsExtensionActions.php`), used by both `ReservationService` and `ConfigOptionSetupService`.
- Audit failures emit a `Log::warning('extension audit write failed', …)` record.
- `tests/Feature/SetupWizardValidationTest.php` no longer contains `markTestSkipped` for the E2E case; three new tests cover reject-invalid, happy-path, and rollback.
- `DECISIONS.md` has two new numbered entries (test-isolation mandate, atomicity contract).
- Full extension phpunit suite green.
- Extension `PROGRESS.md` updated with squash SHA after merge.

---

## Commit sequence

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git fetch origin
git checkout -b dp-13-setupwizard-atomicity-audit-reliability origin/dynamic-slider

# Commit 1 (FIRST — incident prevention)
git commit -m "test(isolation): mirror root phpunit env overrides + DB_DATABASE bootstrap guard (dp-13)"

# Commit 2
git commit -m "feat(setup-wizard): wrap createDynamicSliderOptions in DB::transaction (dp-13)"

# Commit 3
git commit -m "refactor(audit): extract safeAudit trait + add Log::warning on failure (dp-13)"

# Commit 4
git commit -m "test(setup-wizard): Filament action lifecycle E2E covering reject/happy/rollback (dp-13)"

# Commit 5
git commit -m "docs(dp-13): decisions + 09-IMPLEMENTATION invariants + changelog"

git push -u origin dp-13-setupwizard-atomicity-audit-reliability
gh pr create --base dynamic-slider --title "feat(setup-wizard): atomicity + audit reliability + test isolation (dp-13)" --fill
```

Author for every commit: `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`.

---

## Process: Out-of-scope finding handling (inherited from dp-10)

Same protocol as dp-10/dp-11. When CodeRabbit or the implementing agent surfaces work that doesn't belong in dp-13:

1. Identify destination plan: audit queue → new `dp-NN-audit-queue.md`; Filament-specific UX → new plan; observability scheduler → dp-12; blade architecture → `dp-core-02-blade-architecture.md`.
2. Append finding to that plan's "Deferred from dp-13" section.
3. Reply to CodeRabbit: `@coderabbitai Acknowledged. Out of scope for dp-13; deferred to dp-NN. See <plan link>.` Resolve thread.
4. Do NOT silently expand PR scope.

---

## /ralph-loop (verbatim contract)

Same as dp-11. Wait after every push and every `@coderabbitai` mention. Do not commit, mention, or merge while CodeRabbit is re-reviewing. All PR checks must be `SUCCESS`, `mergeStateStatus == CLEAN`, `unresolved threads == 0`, last review `Actionable comments posted: 0`.

Post-merge bookkeeping:
- `cd /var/www/paymenter/extensions/Others/DynamicPterodactyl && git checkout dynamic-slider && git pull --ff-only`
- Append a `dp-13 shipped` row to `PROGRESS.md` with the squash SHA.
- Commit + push the PROGRESS update.
- Archive `.sisyphus/boulder.json` to `.sisyphus/completed/dp-13-setupwizard-atomicity-audit-reliability.boulder.json`.

---

## Out of scope

- Any core (`/var/www/paymenter/app/` or `/var/www/paymenter/themes/`) change.
- `ptero_*` schema changes (atomicity is at the application layer, not the DB).
- `AlertService::checkCapacityAlerts()` wiring (dp-12).
- Off-host backup automation (ops).
- SetupWizard UX/UI changes beyond error messaging produced by the rule validator.
- `AuditLogService` write-behind queue implementation.
- Pricing math, slider UX, auth policies (already shipped via earlier dp-NN).

---

## Delegation

Category: `deep`. One subagent runs all five commits sequentially on one branch.

Agent MUST:

1. Read each cited file end-to-end before editing.
2. Verify `git config user.email` is the noreply form before the first commit.
3. **Commit 1 FIRST** — land the isolation fix before any phpunit run. If for any reason commit 1 is skipped or out-of-order, STOP and escalate; this is non-negotiable post-incident.
4. Implement Commit 1, run tests, commit.
5. Implement Commit 2, run tests (assert new rollback test passes), commit.
6. Implement Commit 3, run tests (assert new Log::warning test passes), commit.
7. Implement Commit 4, run tests (including the three new E2E cases), commit.
8. Implement Commit 5 (docs only), commit.
9. Push: `git push -u origin dp-13-setupwizard-atomicity-audit-reliability`.
10. Open PR against `dynamic-slider`.
11. Run the `/ralph-loop` contract until merged.
12. Apply the out-of-scope handling process to every CodeRabbit thread.
13. **Never run `php artisan migrate:fresh`, `migrate:reset`, `db:wipe`, or any destructive command without `DB_DATABASE=paymenter_test APP_ENV=testing` explicitly prepended, and never on a production host.** The `/usr/local/bin/paymenter-artisan-guard.sh` wrapper should already refuse such calls, but the agent must not attempt to bypass it.
