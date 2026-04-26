# DynamicPterodactyl — Audit Log Coverage Expansion

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/`
**Type**: Add `AuditLogService::log()` calls to every mutation site that currently lacks one.

---

## Problem

Audit finding #4: `AuditLogService` is called from exactly one site today — `ReservationService::cancel()` when `$isAdminAction === true`. Every other mutation (reservation create/confirm/extend, setup-wizard submits, AlertConfig CRUD) is invisible in the audit log.

Impact: the `AuditLogPage` admin screen shows a near-empty table. Admin can't answer "who changed X and when" for most operations.

---

## Design

### What counts as a mutation worth auditing
- Changes that affect capacity accounting (reservation lifecycle).
- Changes that affect pricing or product config (setup wizard).
- Changes to alerting rules (AlertConfig CRUD).

### What does NOT need an audit entry
- Read-only operations.
- `getByToken`, `getByCartItem`, `getAll`, `getStatistics` — reads.
- Pterodactyl API calls — upstream, not ours.
- Scheduled `cleanupExpired` job — transitions pending→expired for many rows per run; audit-logging each would flood the table. Log the run-level summary instead.

### Audit entry shape
Read `AuditLogService::log()` signature first. Typical shape:
```php
$this->auditService->log(
    action: 'created',        // verb in past tense
    targetType: 'reservation', // noun
    targetId: $reservation->id,
    context: [
        // all relevant fields — enough to reconstruct what changed without joining live tables
    ],
    userId: null,             // use auth()->id() if available, null for system actions
);
```

Confirm actual signature before coding. If it differs, adapt all call sites identically.

---

## Exact changes

### `Services/ReservationService.php`

**`create()`** — around line 47 inside the transaction, after `insertGetId`:
```php
$this->auditService->log('created', 'reservation', $id, [
    'token_prefix' => substr($token, 0, 8) . '...',
    'product_id' => $productId,
    'location_id' => $locationId,
    'node_id' => $node['node_id'],
    'memory' => $resources['memory'],
    'cpu' => $resources['cpu'],
    'disk' => $resources['disk'],
    'price' => $pricing['total'],
    'cart_item_id' => $cartItemId,
], $userId);
```

**`confirm()`** — after the UPDATE, only if `$rowsAffected > 0`. Can't use the current one-line `return`; refactor to capture:
```php
$rows = DB::table('ptero_resource_reservations')
    ->where('token', $token)
    ->where('status', 'pending')
    ->where('expires_at', '>', now())
    ->update([
        'status' => 'confirmed',
        'service_id' => $serviceId,
        'updated_at' => now(),
    ]);

if ($rows > 0) {
    $this->auditService->log('confirmed', 'reservation', null, [
        'token_prefix' => substr($token, 0, 8) . '...',
        'service_id' => $serviceId,
    ]);
}

return $rows > 0;
```
Note: `targetId` is null because we don't refetch the reservation. Acceptable; `service_id` is in context.

**`cancel()`** — already audits when `$isAdminAction`. Extend to audit non-admin cancels too, with a discriminator:
```php
if ($result) {
    $this->auditService->log(
        'cancelled',
        'reservation',
        $reservation->id,
        [
            'reason' => $reason,
            'source' => $isAdminAction ? 'admin' : 'system',
            'resources' => [
                'memory' => $reservation->memory,
                'cpu' => $reservation->cpu,
                'disk' => $reservation->disk,
            ],
        ]
    );
}
```
Remove the existing `if ($result && $isAdminAction)` wrapper — unified in the block above.

**`extend()`** — after the UPDATE, capture rowsAffected like `confirm()`:
```php
if ($rows > 0) {
    $this->auditService->log('extended', 'reservation', null, [
        'token_prefix' => substr($token, 0, 8) . '...',
        'additional_minutes' => $additionalMinutes,
    ]);
}
```

**`cleanupExpired()`** — once per run, not per row:
```php
$count = DB::table('ptero_resource_reservations')
    ->where('status', 'pending')
    ->where('expires_at', '<', now())
    ->update(['status' => 'expired', 'updated_at' => now()]);

if ($count > 0) {
    $this->auditService->log('batch_expired', 'reservation', null, [
        'count' => $count,
    ]);
}

return $count;
```

### `Services/ConfigOptionSetupService.php`

Read the file first. For every public method that writes to DB (`ConfigOption::create`, `update`, bulk inserts), append an audit entry:
```php
$this->auditService->log('setup_run', 'product_config', $productId, [
    'sliders_created' => count($created),
    'sliders_updated' => count($updated),
    'model' => $pricingModel,
]);
```
Exact keys depend on what the method actually returns — inspect.

### `Admin/Resources/AlertConfigResource.php`

Filament resources support lifecycle hooks. Two approaches:

**Option A (preferred)**: Add `mutateFormDataBeforeCreate` / `afterSave` callbacks that call `AuditLogService`.
**Option B**: Add an Eloquent observer on the `AlertConfig` model, registered from `DynamicPterodactyl::boot()`.

Pick **B** — observer-based — because it captures mutations from anywhere (API, tinker, other admin panels), not just the Filament resource.

Create `Models/Observers/AlertConfigObserver.php`:
```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models\Observers;

use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;

class AlertConfigObserver
{
    public function __construct(private AuditLogService $audit) {}

    public function created(AlertConfig $config): void
    {
        $this->audit->log('created', 'alert_config', $config->id, $config->only([
            'location_id', 'metric', 'threshold', 'enabled',
        ]));
    }

    public function updated(AlertConfig $config): void
    {
        $this->audit->log('updated', 'alert_config', $config->id, [
            'changes' => $config->getChanges(),
        ]);
    }

    public function deleted(AlertConfig $config): void
    {
        $this->audit->log('deleted', 'alert_config', $config->id, []);
    }
}
```

Register in `DynamicPterodactyl::boot()`:
```php
\Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig::observe(
    \Paymenter\Extensions\Others\DynamicPterodactyl\Models\Observers\AlertConfigObserver::class
);
```

### `Admin/Pages/SetupWizard.php`
Already invoking `ConfigOptionSetupService` — audit will fire there. Verify by reading the wizard submit handler; no direct changes needed if it delegates.

---

## Testing

### Unit tests — extend `ReservationServiceTest`
Add assertions that `AuditLogService::log` is called with the correct params:

1. `test_create_logs_audit_entry` — assert `auditService->log('created', 'reservation', <id>, <context>, <userId>)` fires after insert.
2. `test_confirm_logs_audit_entry_on_success` — assert log fires only when rows affected > 0.
3. `test_confirm_skips_audit_on_state_drift` — expired row confirms false, no audit call.
4. `test_extend_logs_audit_entry_on_success`.
5. `test_cleanup_expired_logs_batch_count` — 5 rows expired → one `batch_expired` audit with `count=5`.
6. `test_cancel_audits_source_admin_vs_system` — parametrised on `$isAdminAction`.

Use the existing `\Mockery::mock(AuditLogService::class)` pattern from the test file.

### Unit tests — new `AlertConfigObserverTest`
Use Laravel model events with `Event::fake` or test with the observer attached and in-memory model. Assert `audit->log` called with correct action + context on each of create/update/delete.

### Manual verification
1. Create a reservation via cart add → check `ptero_audit_logs` for `created` / `reservation` row.
2. Run `SetupWizard` → check row for `setup_run` / `product_config`.
3. Create an `AlertConfig` via Filament → check row for `created` / `alert_config`.
4. Trigger `cleanupExpired` manually → check single `batch_expired` row even with multiple expired reservations.

---

## Risks

| Risk | Mitigation |
|---|---|
| `AuditLogService::log()` signature differs from assumed | Read once at start of implementation; adjust all call sites uniformly. |
| Observer spins up an AuditLogService instance that recursively audits itself | Audit log writes go to `ptero_audit_logs` table, not observed. No recursion possible. |
| High-volume reservations flood audit log | Reservations are user-driven, not machine-driven. Expected rate: tens/hour in production. Not a concern. |
| Existing `cancel()` test expectations break | 1 test currently asserts audit-on-admin-only; update to assert audit-always with source param. |

---

## Acceptance

- `ptero_audit_logs` receives entries for every mutation type listed above.
- All new + existing unit tests pass.
- `AuditLogPage` Filament screen shows a populated, filterable audit trail covering reservations, config setup, and alert-config CRUD.
- No recursion, no flood from scheduled cleanup.

---

## Commit

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git add -A
git commit -m "feat(audit): expand audit log coverage across reservation and admin mutations"
```

---

## Delegation

`task(category="deep", load_skills=[], run_in_background=true, ...)`

Branch setup (run before delegating):

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git fetch origin
git checkout -b dp-03-audit-log-coverage origin/dynamic-slider
```

Agent reads `AuditLogService.php` first to confirm signature, then applies all edits, then runs tests, then commits.

Publish for review:

```bash
git push -u origin dp-03-audit-log-coverage
gh pr create --base dynamic-slider --title "feat(audit): expand audit log coverage across reservation and admin mutations" --fill
```

---

## Out of scope

- Admin UI filters on audit log page — separate plan if needed.
- Audit retention policy (prune rows older than N days) — separate plan.
- Structured diff storage for `updated` events (pre/post values) — current `getChanges()` is adequate.
