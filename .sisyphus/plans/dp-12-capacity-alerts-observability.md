# dp-12 — Capacity-Alert Delivery + Scheduler Wiring + Observability Audit Trail

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (the `dynamic-slider` branch in the extension repo at `https://github.com/Jordanmuss99/dynamic-pterodactyl.git`). Extension-only; no core changes.
**Type**: Correctness + wiring + observability patch series. Same shape as dp-11 / dp-13.
**Delivery**: Single PR, atomic commit per concern, squash-merge.
**Backlog mapping**: Fulfils the dp-12 breadcrumbs carried across prior plans:
- `.sisyphus/plans/dp-07-doc-consolidation.md:229` — "Capacity alert scheduling and email delivery (dp-12)."
- `.sisyphus/plans/dp-08-reservation-verification.md:307-308` — "Reservation funnel observability / metrics — dp-12."
- `.sisyphus/plans/dp-09-extension-pricing-cleanup.md:233` — "Capacity-alert observability (dp-12)."
- `.sisyphus/plans/dp-10-slider-ux-a11y.md:196,250` — observability drafted into dp-12.
- `.sisyphus/plans/dp-11-authorization-surface-reduction.md:170-172,201,257,305` — wire `checkCapacityAlerts()` to scheduler; dp-12.
- `.sisyphus/plans/dp-13-setupwizard-atomicity-audit-reliability.md:227,333` — `AlertService::checkCapacityAlerts()` scheduler wiring is dp-12's job.
- `.sisyphus/dynamic-pterodactyl-reservation-lifecycle-fixes.md:263` — admin-notify email for confirm-failure (finding #2 residual TODO) — pairs with existing `AlertService` mail-setup TODO.

**Explicitly NOT in scope**: `FORK-NOTES.md:109` ("Integer-cents / money-library migration") was mis-tagged dp-12. dp-12 is **observability + alert delivery only**. The money-library migration will get its own plan later.

---

## Problem

Four concrete gaps, verified by recon:

### 1. Capacity-alert email delivery is a logged stub (`Services/AlertService.php:97-140`)

The `sendNotifications()` email branch writes a `Log::info('Capacity alert email would be sent', [...])` entry at `:101-109` and returns. **No email is ever sent.** The webhook branch at `:112-139` works, but webhooks are optional (`webhook_url` is nullable in `ptero_alert_configs`). In practice, admins with only email configured receive nothing when a node crosses its warning/critical threshold.

This has been broken since before dp-04 (dp-04 explicitly scoped itself to shortfall notifications and left capacity alerts alone — see `.sisyphus/notepads/dp-04-shortfall-notifications/decisions.md:1-2`). **Severity: High** — a shipped capacity-alert feature that silently drops every notification is worse than not shipping it at all.

### 2. `AlertService::checkCapacityAlerts()` has zero callers (`Services/AlertService.php:21-33`)

The scanner exists, iterates active `ptero_alert_configs` rows, enforces cooldown, checks thresholds, dispatches. **Nothing schedules it.** Grep across the whole extension returns only its definition site — no `$schedule->call(...)`, no artisan command, no controller, no job, no listener.

Meanwhile the proven scheduler pattern already lives at `DynamicPterodactyl.php:95-120` for `ReservationService::cleanupExpired()`:

```php
Schedule::call(fn () => app(ReservationService::class)->cleanupExpired())
    ->everyMinute()
    ->name('dynamic-pterodactyl:cleanup-expired-reservations')
    ->withoutOverlapping();
```

dp-12 mirrors this verbatim for `checkCapacityAlerts()`. **Severity: High** — the feature cannot fire without being wired in.

### 3. Capacity alerts leave no audit trail (`Services/AlertService.php:97-140`)

When a capacity alert IS successfully dispatched (via webhook today), nothing writes to `ptero_audit_logs`. Operators reviewing "when did we last alert on node X?" get no record — only the `last_notification_at` column on `ptero_alert_configs`, which is overwritten on every fire and carries no payload.

Compare `AlertConfigObserver` at `Models/Observers/AlertConfigObserver.php:12-69` which DOES audit config changes (including redaction of `webhook_url`). The actual notification events are silent.

dp-13 shipped the `AuditsExtensionActions` trait (`Services/Concerns/AuditsExtensionActions.php`) with `safeAudit()` — dp-12 reuses it. **Severity: Medium** — observability gap that compounds with gap #1; once email works, we still can't see post-facto what was sent.

### 4. Reservation state-transition observability is unstructured (`Services/ReservationService.php`)

`confirm()`, `cancel()`, `cleanupExpired()` all execute state transitions on `ptero_resource_reservations`. Some log via `Log::info`/`Log::warning` (see `Listeners/InvoicePaidListener.php:44-45, 66-86, 99-123`), but there is no structured audit row per transition. Result: "how many reservations expired last week?" / "why did this token flip to cancelled?" questions have no DB-backed answer.

dp-08 explicitly parked this as "Reservation funnel observability / metrics — dp-12" (`.sisyphus/plans/dp-08-reservation-verification.md:307-308`). dp-12 adds `safeAudit()` calls at the three transition sites — minimal code, maximum visibility. **Severity: Medium** — diagnostic gap that grows worse as reservation volume increases.

---

## Design

Five concerns, five commits. Each commit must keep the extension phpunit suite green. Run from the extension dir:

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
../../../vendor/bin/phpunit --configuration phpunit.xml
```

Baseline: 107 tests passing, 1 skipped (post-dp-13). Expected post-dp-12: 113-118.

### Commit 1 — Capacity-alert email delivery (FIRST — fixes the shipped stub)

**Files**:
- `Notifications/CapacityAlertNotification.php` (new) — mirrors `Notifications/ReservationShortfallNotification.php`
- `Services/AlertService.php` — replace the logged-stub email branch at `:101-109` with real `Notification::send()`
- `tests/Unit/AlertServiceTest.php` — add three new tests

**Why first**: before the scheduler (commit 2) wakes this code path up in production, email delivery must actually work. Otherwise the scheduler would immediately start emitting the stub log every 5 minutes until commit 2 lands. Landing email first means the scheduler, when wired, works end-to-end.

**New file** `Notifications/CapacityAlertNotification.php`:
```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig;

class CapacityAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public AlertConfig $alertConfig,
        public array $breachedThresholds,
        public array $utilizationSnapshot,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $severity = in_array('critical', array_column($this->breachedThresholds, 'severity'), true) ? 'CRITICAL' : 'WARNING';

        $message = (new MailMessage)
            ->subject("[{$severity}] Pterodactyl capacity alert: {$this->alertConfig->name}")
            ->greeting("Capacity alert: {$this->alertConfig->name}");

        foreach ($this->breachedThresholds as $breach) {
            $message->line("{$breach['resource']} at {$breach['usage_percent']}% (threshold {$breach['threshold']}%, {$breach['severity']})");
        }

        return $message
            ->line("Location scope: " . ($this->alertConfig->location_id ?? 'all'))
            ->action('View alert config', url("/admin/alert-configs/{$this->alertConfig->id}/edit"));
    }
}
```

**Modified** `Services/AlertService.php:97-109` — replace the stub with:
```php
if ($alertConfig->notification_email) {
    $recipients = User::whereNotNull('role_id')->get();
    if ($recipients->isEmpty()) {
        Log::warning('No admin recipients configured for capacity alert', [
            'alert_config_id' => $alertConfig->id,
        ]);
    } else {
        foreach ($recipients as $admin) {
            try {
                $admin->notify(new CapacityAlertNotification(
                    $alertConfig,
                    $breaches,
                    $snapshot,
                ));
            } catch (\Throwable $e) {
                Log::warning('Failed to send capacity alert email', [
                    'alert_config_id' => $alertConfig->id,
                    'recipient_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);
                report($e);
            }
        }
    }
}
```

The existing webhook branch at `:112-139` is untouched.

**Test additions** in `tests/Unit/AlertServiceTest.php`:
1. `test_capacity_alert_email_fans_out_to_all_admins`: `Notification::fake()`, stub `ResourceCalculationService` to return breach, assert `CapacityAlertNotification` dispatched to each `User` with `role_id`.
2. `test_capacity_alert_email_logs_warning_when_no_admins`: no admins in DB, assert `Log::warning('No admin recipients...')` captured.
3. `test_capacity_alert_email_logged_on_dispatch_failure`: mock `$admin->notify()` to throw, assert `Log::warning('Failed to send capacity alert email')` + `report()` called, loop continues to next admin.

**Commit message**: `feat(alerts): send capacity-alert emails instead of logging stub (dp-12)`

### Commit 2 — Schedule `checkCapacityAlerts` (wake up the scanner)

**Files**:
- `DynamicPterodactyl.php` — add `Schedule::call(...)` for capacity alerts in `boot()`
- `tests/Feature/AlertScheduleTest.php` (new) — assert the schedule is registered with the expected name and cadence

**Change**: inside `boot()`, after the existing reservation cleanup schedule (around `DynamicPterodactyl.php:95-120`), add:
```php
Schedule::call(fn () => app(AlertService::class)->checkCapacityAlerts())
    ->everyFiveMinutes()
    ->name('dynamic-pterodactyl:check-capacity-alerts')
    ->withoutOverlapping();
```

Cadence rationale: `ptero_resource_reservations.cooldown_minutes` defaults to 60 (`database/migrations/2025_01_01_000004_create_ptero_alert_configs_table.php:18-35`); a 5-minute scan is frequent enough to catch threshold crossings within one cooldown window without hammering the Pterodactyl API (the scanner reads live node state via `ResourceCalculationService`).

Import `AlertService` if not already imported.

**Test additions** in `tests/Feature/AlertScheduleTest.php`:
1. `test_capacity_alert_schedule_is_registered`: `$schedule = app(Schedule::class); $events = collect($schedule->events()); assert one has command/description matching 'dynamic-pterodactyl:check-capacity-alerts' with everyFiveMinutes cron '*/5 * * * *'`.
2. `test_capacity_alert_schedule_uses_withoutOverlapping`: inspect the event's `$withoutOverlapping` flag.

**Commit message**: `feat(alerts): schedule checkCapacityAlerts every 5 minutes (dp-12)`

### Commit 3 — Audit trail for capacity alerts

**Files**:
- `Services/AlertService.php` — `use AuditsExtensionActions;`, write audit rows after successful send
- `tests/Unit/AlertServiceTest.php` — add two tests

**Change**: add the trait import inside the class:
```php
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\Concerns\AuditsExtensionActions;

class AlertService
{
    use AuditsExtensionActions;
    // ...existing body
}
```

After a successful email or webhook send (inside `sendNotifications()`, after the dispatch succeeds for either channel), call:
```php
$this->safeAudit('capacity_alert_sent', 'alert_config', $alertConfig->id, [
    'channels' => $deliveredChannels,       // ['email', 'webhook']
    'severity' => $severity,                // 'warning' | 'critical'
    'breached' => array_column($breaches, 'resource'),
    'location_scope' => $alertConfig->location_id,
]);
```

Only write ONE audit row per `sendNotifications()` invocation, summarising what was delivered. If both email and webhook fire, one audit row lists both channels.

**Test additions** in `tests/Unit/AlertServiceTest.php`:
1. `test_capacity_alert_writes_audit_row_on_successful_send`: `Notification::fake()`, trigger alert, assert `ptero_audit_logs` has one row with `action='capacity_alert_sent'`, `entity_type='alert_config'`, `entity_id=$alertConfig->id`, `new_values.channels=['email']`.
2. `test_capacity_alert_audit_is_best_effort`: mock `AuditLogService::log()` to throw, assert notification still dispatches, assert `Log::warning('extension audit write failed', ...)` captured.

**Commit message**: `feat(alerts): audit capacity-alert delivery via AuditsExtensionActions trait (dp-12)`

### Commit 4 — Reservation funnel observability audit trail

**Files**:
- `Services/ReservationService.php` — `use AuditsExtensionActions;`, add `safeAudit()` calls at the three state-transition sites
- `tests/Unit/ReservationServiceTest.php` — add three tests

**Change**: add the trait, then instrument the transitions:

Inside `confirm()` (existing at `:105` area) — after the `update()` returns > 0:
```php
$this->safeAudit('reservation_confirmed', 'resource_reservation', $reservationId, [
    'token_prefix' => substr($token, 0, 8),
    'service_id' => $serviceId,
    'node_id' => $reservation->node_id,
]);
```

Inside `cancel()` — after successful cancel:
```php
$this->safeAudit('reservation_cancelled', 'resource_reservation', $reservationId, [
    'token_prefix' => substr($token, 0, 8),
    'node_id' => $reservation->node_id,
]);
```

Inside `cleanupExpired()` (at `:266` area) — after the batch update, emit ONE audit row per run summarising the count (not per-reservation, to avoid flooding the audit log):
```php
if ($expiredCount > 0) {
    $this->safeAudit('reservations_expired_batch', 'resource_reservation', 0, [
        'count' => $expiredCount,
        'run_at' => now()->toIso8601String(),
    ]);
}
```

Use `entity_id = 0` for batch rows (documented in commit 5's DECISIONS.md entry).

**Test additions** in `tests/Unit/ReservationServiceTest.php`:
1. `test_confirm_writes_audit_row`: pending reservation + successful confirm → `ptero_audit_logs` has `action='reservation_confirmed'` with `token_prefix` and `service_id` in `new_values`.
2. `test_cancel_writes_audit_row`: pending reservation + successful cancel → `action='reservation_cancelled'`.
3. `test_cleanupExpired_writes_batch_audit_row_with_count`: seed N expired reservations, run cleanup, assert one audit row with `action='reservations_expired_batch'` and `new_values.count=N`.

**Commit message**: `feat(reservations): audit state transitions for funnel observability (dp-12)`

### Commit 5 — Docs + DECISIONS entries

**Files**:
- `DECISIONS.md` — three new numbered entries
- `09-IMPLEMENTATION.md` — section on capacity-alert delivery contract + scheduler cadence + observability audit schema
- `CHANGELOG.md` — `[Unreleased]` → `### Added` and `### Fixed` entries
- `FORK-NOTES.md:109` — clarify that dp-12 is observability, NOT the money-library migration (append a one-liner redirecting money work to a future plan)

**DECISIONS.md** additions (numbering continues from the last existing decision):

```markdown
### Decision N: Capacity-Alert Delivery Contract (dp-12, Apr 2026)
`AlertService::checkCapacityAlerts()` is the single entry point for capacity threshold scanning. It is scheduled by `DynamicPterodactyl.php::boot()` every 5 minutes with `withoutOverlapping()`. Delivery channels: `mail` (always, if `notification_email` is true) and `webhook` (if `webhook_url` set). Email fan-out uses `User::whereNotNull('role_id')->get()` — same recipient rule as `notifyShortfall()` (dp-04). Failures on ONE recipient do not abort the loop; they emit `Log::warning` + `report()`.

### Decision N+1: Capacity-Alert Scheduler Cadence (dp-12, Apr 2026)
Cadence: `everyFiveMinutes()`. Rationale: `ptero_alert_configs.cooldown_minutes` defaults to 60; a 5-minute scan catches threshold crossings within one cooldown window without API hammering. Cadence is code-only (no runtime toggle) — change requires a code edit + deploy. If production telemetry shows API pressure, downgrade to `everyTenMinutes()` in a follow-up.

### Decision N+2: Reservation Funnel Observability Schema (dp-12, Apr 2026)
Reservation state transitions write rows to `ptero_audit_logs` via the shared `AuditsExtensionActions` trait (from dp-13). Per-transition rows for `confirm` / `cancel` (entity_id = reservation id). Batch rows for `cleanupExpired` (entity_id = 0, with `count` in `new_values`). Token values are logged as `token_prefix` (first 8 chars) only — full tokens are sensitive and must never land in audit JSON.
```

**09-IMPLEMENTATION.md** additions — append a new section:

```markdown
## Capacity Alerts + Reservation Observability (dp-12)

`AlertService::checkCapacityAlerts()` is scheduled every 5 minutes in `DynamicPterodactyl.php::boot()`. For each active `ptero_alert_configs` row it: (1) respects `cooldown_minutes` via `last_notification_at`, (2) reads live utilization via `ResourceCalculationService`, (3) dispatches to email (all admins with `role_id`) and/or webhook, (4) writes one `capacity_alert_sent` audit row summarising channels + severity + breached resources.

`ReservationService::{confirm,cancel,cleanupExpired}` write audit rows on successful state transitions. `confirm` / `cancel` are per-reservation. `cleanupExpired` writes one batch row per run with `count`. Token values are stored as `token_prefix` only.

Both services use the shared `AuditsExtensionActions` trait (dp-13) for audit dispatch — audit failure is best-effort and does not abort business logic.
```

**CHANGELOG.md** — add to `[Unreleased]`:
```markdown
### Added
- Scheduled `AlertService::checkCapacityAlerts()` every 5 minutes via `DynamicPterodactyl.php::boot()` (dp-12).
- `Notifications/CapacityAlertNotification` mail notification for capacity threshold breaches (dp-12).
- Audit trail for capacity alert dispatch and reservation state transitions (`confirm`, `cancel`, `cleanupExpired`) using the shared `AuditsExtensionActions` trait (dp-12).

### Fixed
- Capacity-alert email delivery: replaced `Log::info('email would be sent')` stub in `AlertService::sendNotifications()` with real `Notification::send()` fan-out to admin users (dp-12).
```

**FORK-NOTES.md** — one-line addendum after `:109` clarifying dp-12 scope:
```markdown
> Note: dp-12 (shipped Apr 2026) covers capacity-alert delivery and observability only. The integer-cents / money-library migration noted above remains deferred to a future dp-NN plan.
```

**No code changes in this commit.**

**Commit message**: `docs(dp-12): decisions + 09-IMPLEMENTATION invariants + changelog`

---

## Deferred (out of scope for dp-12)

- **`AlertService` service decomposition** — splitting capacity scanning and shortfall notification into separate classes. Recorded in `.sisyphus/plans/dp-11-authorization-surface-reduction.md:260` ("service decomposition"). Defer to a future `dp-NN-service-decomposition.md`.
- **Queue-based async alert delivery** — sending alerts via `ShouldQueue` + retries. Current synchronous in-process path is simpler; upgrade if scanner duration becomes a problem. Defer.
- **Admin-notify email when `InvoicePaidListener::confirm()` returns false** (from `.sisyphus/dynamic-pterodactyl-reservation-lifecycle-fixes.md:263`). Related but scoped to listener branch; keep in its own future plan so dp-12 doesn't grow.
- **Integer-cents / money-library migration** (`FORK-NOTES.md:109`). dp-12 explicitly clarifies this is NOT dp-12 (see commit 5). New plan needed.
- **Slack / Discord notification channels**. Defer to a transport-expansion plan.
- **Capacity alert rate limiting BEYOND existing `cooldown_minutes`**. Current cooldown is sufficient; revisit if flood incidents occur.
- **Prometheus / metrics endpoint** for reservation funnel counters. Audit-log rows are a per-event record; aggregated metrics are a separate observability layer. Defer.
- **TTL config live-reload** (from reservation-lifecycle doc finding #4). Ops concern, already parked.

---

## Testing

- After commit 1: run `cd extensions/Others/DynamicPterodactyl && ../../../vendor/bin/phpunit --configuration phpunit.xml`. Must stay green. New tests assert email dispatch, empty-admins warning, per-recipient failure isolation.
- After commit 2: new `AlertScheduleTest` passes; existing suite stays green.
- After commit 3: audit-row tests pass; `safeAudit` failure isolation test passes.
- After commit 4: three new `ReservationServiceTest` cases pass; existing reservation tests untouched.
- Final suite: expected 113-118 tests (107 baseline + 2 email + 1 warn + 1 failure-isolation + 2 schedule + 2 audit + 3 reservation transitions; some may consolidate).
- Manual smoke (post-merge, staging):
  1. `php artisan schedule:list | grep dynamic-pterodactyl` → two lines (cleanup + capacity alerts).
  2. Seed an `AlertConfig` with low thresholds + `notification_email=true`, wait ≤5 min, confirm the admin inbox received `CapacityAlertNotification`. Confirm one `ptero_audit_logs` row written.
  3. Confirm a reservation, cancel a reservation, wait for one expired → three new `ptero_audit_logs` rows with the expected `action` values.

---

## Risks

| Risk | Mitigation |
|---|---|
| Scheduling `checkCapacityAlerts()` every 5 minutes hits the Pterodactyl API harder than expected | Existing cooldown (`cooldown_minutes`, default 60) throttles per-config scanning. Decision N+1 documents the downgrade path. Monitor API timing in the first week post-deploy. |
| Email fan-out to all admins creates notification noise | `cooldown_minutes` + `notification_email` toggle on each `AlertConfig` give ops fine-grained control. Consider per-admin preferences in a later plan. |
| Audit-log volume grows from batch cleanup rows | Batch rows are 1-per-run (per-minute schedule → ≤1440/day). `ptero_audit_logs` already handles reservation + config audit volume. Acceptable. |
| `CapacityAlertNotification` markup differs from `ReservationShortfallNotification` UX | Mirror structure and tone of the dp-04 notification; reviewer check during `/ralph-loop`. |
| Adding `use AuditsExtensionActions` to `ReservationService` surfaces constructor DI issues | Trait uses `app(AuditLogService::class)` lookup (per dp-13 decision), no constructor change needed. |
| Test for `Schedule::` registration is brittle across Laravel minor versions | Test reads the framework-internal `$schedule->events()` list. If the shape changes in a Laravel point release, update the test in the same follow-up. Not a release blocker. |
| Replacing the email stub with real `Notification::send` fires against the shared dev inbox on first deploy | Extension phpunit already sets `MAIL_MAILER=array` (dp-13 commit 1). Production deploy: verify an `AlertConfig` with `notification_email=true` does not exist until ops are ready. |

---

## Acceptance

- Branch `dp-12-capacity-alerts-observability` on the extension fork, PR opened against `dynamic-slider`.
- All five commits land; squash-merge as one PR.
- `AlertService::sendNotifications()` dispatches `CapacityAlertNotification` via `Notification` facade (no more stub log).
- `DynamicPterodactyl.php::boot()` registers `dynamic-pterodactyl:check-capacity-alerts` on `everyFiveMinutes()` with `withoutOverlapping()`.
- `AlertService` uses `AuditsExtensionActions` trait; one audit row per successful `sendNotifications()` call.
- `ReservationService` uses `AuditsExtensionActions` trait; per-transition audit rows on `confirm`/`cancel` and one batch row per `cleanupExpired` run.
- `DECISIONS.md` has three new numbered entries.
- `09-IMPLEMENTATION.md` has the new Capacity Alerts + Reservation Observability section.
- `FORK-NOTES.md:109` carries the dp-12 scope clarifier.
- Full extension phpunit suite green (113-118 tests).
- Extension `PROGRESS.md` updated with squash SHA after merge.

---

## Commit sequence

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git fetch origin
git checkout -b dp-12-capacity-alerts-observability origin/dynamic-slider

# Commit 1 (FIRST — fix the shipped stub before scheduler wakes it up)
git commit -m "feat(alerts): send capacity-alert emails instead of logging stub (dp-12)"

# Commit 2
git commit -m "feat(alerts): schedule checkCapacityAlerts every 5 minutes (dp-12)"

# Commit 3
git commit -m "feat(alerts): audit capacity-alert delivery via AuditsExtensionActions trait (dp-12)"

# Commit 4
git commit -m "feat(reservations): audit state transitions for funnel observability (dp-12)"

# Commit 5
git commit -m "docs(dp-12): decisions + 09-IMPLEMENTATION invariants + changelog"

git push -u origin dp-12-capacity-alerts-observability
gh pr create --base dynamic-slider --title "feat(alerts): capacity-alert delivery + scheduler + observability audit (dp-12)" --fill
```

Author for every commit: `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`. Verify with `git config user.email` before the first commit and with `git log -1 --format='%an <%ae>'` after each.

---

## Process: Out-of-scope finding handling (inherited from dp-10/dp-11/dp-13)

Same protocol. When CodeRabbit or the implementing agent surfaces work that doesn't belong in dp-12:

1. Identify destination plan:
   - AlertService decomposition → new `dp-NN-service-decomposition.md`.
   - Queue-based async alerts → new `dp-NN-alert-queue.md`.
   - Money-library / integer cents → new `dp-NN-money-migration.md`.
   - Slack/Discord transport → new `dp-NN-alert-transports.md`.
   - Admin-notify on confirm() failure (reservation-lifecycle doc finding #2 TODO) → new `dp-NN-reservation-admin-notify.md`.
   - Blade architecture → `.sisyphus/plans/dp-core-02-blade-architecture.md` (exists).
2. Append finding to that plan's "Deferred from dp-12" section with description, file:line, citation (CodeRabbit thread URL or "found during dp-12 commit N"), date.
3. Reply to CodeRabbit: `@coderabbitai Acknowledged. Out of scope for dp-12; deferred to dp-NN. See <plan link>.` Resolve thread.
4. **Do NOT silently expand PR scope.**

---

## /ralph-loop (verbatim contract)

> Use `/ralph-loop` to review the PR, read CodeRabbit's latest comments and decide if they are relevant. If not, mention CodeRabbit with `@coderabbitai` explaining why you are rejecting. If you agree, make the changes alongside any other issues you find. When done, push and **wait** for CodeRabbit to review again. Loop until you and CodeRabbit are satisfied; then merge.

> The agent **HAS to wait** for CodeRabbit's review after a commit or when mentioning CodeRabbit. **All PR checks must be passed and not pending.** **If CodeRabbit is doing a re-review then you need to WAIT for it to finish and reply first.**

Operationalised:

| Trigger | Mandatory wait | Polling cadence |
|---|---|---|
| `git push` | wait for CodeRabbit incremental review (≈3–8 min) | poll every 60–90s |
| `@coderabbitai review` mention | wait for new `submittedAt` review timestamp newer than the mention | poll every 60–90s |
| CodeRabbit re-review IN_PROGRESS | wait until status leaves IN_PROGRESS (no commits, no mentions, no merges) | poll every 60s |
| Stuck >45 min with no activity | re-trigger with `@coderabbitai review`, then wait again | — |

Merge pre-conditions (ALL must hold simultaneously):
- `mergeStateStatus == "CLEAN"` and `mergeable == "MERGEABLE"`
- All status checks `SUCCESS` (no `PENDING`, no `FAILURE`, no missing rollup entries)
- `unresolved review threads == 0` (verify via the GraphQL `reviewThreads.isResolved` field)
- Last CodeRabbit review reports `Actionable comments posted: 0`, OR a verbal CodeRabbit confirmation that the latest commit is clean, OR CodeRabbit has been triggered twice with no new actionable comments

Rejection protocol (CodeRabbit comment is not relevant):
- Post a single `@coderabbitai` reply explaining why the comment doesn't apply (cite the file/line, design decision, or the dp-NN plan it has been deferred to per the process above).
- Wait for CodeRabbit's response.
- Do not ignore comments silently; do not close threads without a reply.

Post-merge bookkeeping:
- `cd /var/www/paymenter/extensions/Others/DynamicPterodactyl && git checkout dynamic-slider && git pull --ff-only`
- Get the squash SHA from `gh pr view <n> --json mergeCommit`.
- Append a `dp-12 shipped` row to `PROGRESS.md` with the squash SHA (short form, 7-8 chars, matching prior rows).
- Commit + push the PROGRESS update on `dynamic-slider`.
- Archive `.sisyphus/boulder.json` to `.sisyphus/completed/dp-12-capacity-alerts-observability.boulder.json` and remove the active file.

---

## Out of scope

- Any core (`/var/www/paymenter/app/` or `/var/www/paymenter/themes/`) change.
- `ptero_*` schema changes.
- `AlertService` service decomposition.
- Queue-based async alert delivery.
- Slack / Discord notification transports.
- Prometheus / metrics endpoint.
- Money-library / integer-cents migration.
- Admin-notify email on `InvoicePaidListener::confirm()` failure.
- Per-admin notification preferences.
- SetupWizard / Filament UX changes.

---

## Delegation

Category: `deep`. One subagent runs all five commits sequentially on one branch.

Agent MUST:

1. Read each cited file end-to-end before editing. Confirm actual line numbers match plan — structures may have drifted since this plan was written.
2. Verify `git config user.email` is the noreply form before the first commit.
3. **Commit 1 FIRST** — ship email delivery before the scheduler wakes the scanner up. If for any reason the order is inverted, STOP and escalate.
4. Implement Commit 1, run tests (assert new `CapacityAlertNotification` dispatches + failure-isolation tests pass), commit.
5. Implement Commit 2, run tests (assert schedule registration tests pass), commit.
6. Implement Commit 3, run tests (assert audit-row + best-effort-failure tests pass), commit.
7. Implement Commit 4, run tests (assert three new reservation-transition audit tests pass), commit.
8. Implement Commit 5 (docs only), commit.
9. Push: `git push -u origin dp-12-capacity-alerts-observability`.
10. Open PR against `dynamic-slider`.
11. Run the `/ralph-loop` contract until merged. Wait after every push and every `@coderabbitai` mention. Do not commit, mention, or merge while CodeRabbit is re-reviewing.
12. Apply the out-of-scope handling process to every CodeRabbit thread.
13. **Never run `php artisan migrate:fresh`, `migrate:reset`, `db:wipe`, or any destructive command without `DB_DATABASE=paymenter_test APP_ENV=testing` explicitly prepended, and never on a production host.** The `/usr/local/bin/paymenter-artisan-guard.sh` wrapper should already refuse such calls, but the agent must not attempt to bypass it.
14. All commits authored as `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`.
