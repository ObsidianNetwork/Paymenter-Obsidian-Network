# dp-17 — Alert Escalation: failure modes that must not be silent

**Source**: dp-audit-2026-04-26 finding F4.
**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (nested git repo, branch `dynamic-slider`).
**Type**: Operational hardening. `AlertService` and the cron path that drives it.
**Effort**: M (half day).
**Severity**: high.
**Suggested branch**: `dp-17-alert-escalation`.

---

## Problem

dp-12 wired `AlertService::checkCapacityAlerts()` to a 5-minute scheduled task and added the email path. Per audit finding F4, the failure modes that BYPASS notification entirely are:

### Failure mode 1 — no admin recipients configured

`Services/AlertService.php:137-143` (`sendEmailAlert`):
```php
$recipients = User::where('role_id', '!=', null)->get();
if ($recipients->isEmpty()) {
    Log::info('AlertService: No admin recipients configured for email alert', [...]);
    return;
}
```

Same pattern at `:250-254` for shortfall alerts. **Net effect**: capacity threshold breached, but no admins exist (or all admins have null `role_id`). Alert silently swallowed.

### Failure mode 2 — webhook delivery fails

`Services/AlertService.php:194-198`:
```php
} catch (\Throwable $e) {
    Log::warning('AlertService: webhook delivery failed', [
        'error' => $e->getMessage(),
        'config_id' => $config->id,
    ]);
}
```

Webhook exceptions logged at `warning` and swallowed. No retry, no fallback channel, no admin-visible health flag.

### Failure mode 3 — entire alert check throws

`Services/AlertService.php:67-70`:
```php
} catch (\Throwable $e) {
    Log::warning('AlertService: Alert check failed', ['error' => $e->getMessage()]);
}
```

Top-level exception handler. If `checkAlertConfig()` throws (DB connection, Pterodactyl 5xx, anything), the exception is logged at warning and the next config is processed. **No persistent record that this admin's alert is broken.**

## Goal

Three guarantees:
1. **At least one channel must succeed for an alert to be considered delivered.** If both email and webhook fail, escalate.
2. **Persistent visibility for delivery health.** Admin can see "alert config X has failed delivery N times in the last hour" without grepping logs.
3. **No silently-swallowed alerts.** Every alert-fire decision produces either a successful send or a recorded failure.

## Design

### New: `ptero_alert_delivery_log` table

Lightweight audit table:

```
id              bigint
alert_config_id foreign key
trigger_type    enum('capacity_breach', 'shortfall', 'state_drift', 'check_failure')
attempted_at    timestamp
channels_tried  json (['email', 'webhook'])
channels_ok     json (['email'])
channels_failed json (['webhook'])
last_error      text nullable
```

One row per alert decision. Lets dashboards / alerts / queries answer "what did we try to send and where did it land".

### Escalation rule

`AlertService::sendNotifications()` becomes:
1. Try email channel. Record outcome.
2. Try webhook channel. Record outcome.
3. If BOTH failed (or "no recipients" + "no webhook configured"): write a row with `trigger_type='check_failure'` and emit a Laravel `Event` (`AlertDeliveryFailed`). Future operators can subscribe a global handler (e.g. PagerDuty integration, ops Slack).
4. The base log message stays at `warning` level for grepability, but the persistent record is the canonical signal.

### Admin UI surface

Add a "Recent Delivery Failures" widget to `Admin/Pages/AuditLogPage.php` (or a new section) showing the last 50 rows from `ptero_alert_delivery_log` where `channels_failed` is non-empty. Trivial Eloquent query + Filament table.

### Backfill / migration safety

Migration adds the table; no destructive change. `down()` drops the table cleanly.

## Edits

- New migration: `database/migrations/2026_04_26_NNNN_create_ptero_alert_delivery_log_table.php`.
- New model: `Models/AlertDeliveryLog.php` (HasMany relation from `AlertConfig`).
- Refactor `Services/AlertService.php` send paths to record per-channel outcomes.
- New event class: `Events/AlertDeliveryFailed.php`.
- New widget/section in `Admin/Pages/AuditLogPage.php` (or co-located on Dashboard) showing recent failures.
- Tests: extend `tests/Unit/AlertServiceTest.php` with the three failure-mode scenarios. Each must assert a delivery-log row was written.

## Tests

Three new unit tests against `AlertServiceTest`:

1. `test_alert_with_no_recipients_records_check_failure_row`
2. `test_alert_with_failed_webhook_records_channels_failed`
3. `test_alert_with_both_channels_failed_dispatches_alert_delivery_failed_event`

Plus one feature test: `test_admin_can_see_recent_delivery_failures_in_ui` (smoke check that the UI widget renders the recorded failures).

## Acceptance

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
../../../vendor/bin/phpunit tests/Unit/AlertServiceTest.php   # green incl. 3 new tests
../../../vendor/bin/phpunit tests/Feature/                    # green
# manual smoke: trigger an alert with empty admin set, confirm a row is written
```

## Commit

Two commits:
1. `feat(ops): persistent alert-delivery log + AlertDeliveryFailed event`
2. `feat(admin-ui): show recent alert-delivery failures in audit-log page`

OR one commit if the UI piece is genuinely tiny. Pick what looks cleanest after implementation.

## Delegation

`task(category="deep", load_skills=["code-review"], run_in_background=true, ...)`. Real design surface (new table, new event, refactor of send path). Deep category gives appropriate budget.

## Status

- [x] Plan written (you are here)
- [x] Delegated to subagent (`bg_66b2dfee` / `ses_235b73019ffeyFiQeWkAw1FZh8`, Sisyphus-Junior, category=deep)
- [x] Migration + model
- [x] AlertService refactor + event
- [x] Admin UI widget
- [x] All new tests green; existing test suite still green
- [x] PR opened
- [x] CR review cycle complete (fixes in 8cd56f2)
- [x] PR merged (b7cdd54, squash into dynamic-slider)
- [x] PROGRESS.md updated; CHANGELOG.md `[Unreleased]` entry
