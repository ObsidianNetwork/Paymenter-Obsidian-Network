# dp-16 — Docs sync: API + Admin + Implementation roadmap

**Source**: dp-audit-2026-04-26 finding F3.
**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (nested git repo, branch `dynamic-slider`).
**Type**: Documentation rewrite. No code changes. Same flavour as the dp-01 closeout (dp-01-doc-refresh-skeleton-delete).
**Effort**: M (half day; mechanical but spans 3+ spec files).
**Severity**: medium.
**Suggested branch**: `dp-16-docs-sync`.

---

## Problem

Three spec files describe a pre-dp-09 architecture that no longer matches the shipped code. Per audit finding F3:

### `03-API.md:45-74` — phantom AdminController

The doc still documents an `AdminController` exposing `/dashboard`, `/statistics`, `/test-connection`, `/validate-config/{productId}`, `/audit-log`, `/import`, `/export`, `/extend`, and `/cleanup` endpoints. **None of these exist.** Shipped admin API surface (per `routes/api.php:32-40`):

- `GET  /admin/reservations` → `AdminReservationController::index`
- `POST /admin/reservations/{token}/cancel` → `AdminReservationController::cancel`
- `GET  /admin/capacity` → `AdminCapacityController::summary`
- `GET  /admin/availability/{locationId}/nodes` → `AvailabilityController::getNodes`

Plus `Http/Controllers/AdminController.php` does not exist on disk.

### `05-ADMIN-UI.md:12-20` — retired Filament resources

Lists `PricingConfigResource` (5-tab editor), `Analytics` page, separate `Settings` page. **None remain after dp-09 + dp-13.** Shipped Filament surface:

- `Admin/Pages/Dashboard.php`
- `Admin/Pages/NodeMonitoring.php`
- `Admin/Pages/AuditLogPage.php`
- `Admin/Pages/SetupWizard.php` (replaces both PricingConfigResource and Settings page per dp-13)
- `Admin/Resources/AlertConfigResource.php`
- `Admin/Resources/ReservationResource.php`

### `09-IMPLEMENTATION.md:30-61` — pre-dp-09 roadmap

Roadmap table still shows pricing-config implementation as in-progress, references `PricingConfigValidator` and pricing-config admin pages. The actual roadmap should reflect: pricing moved to core (dp-core-01), extension validators retired (dp-09), wizard atomicity hardened (dp-13).

## Goal

03-API.md, 05-ADMIN-UI.md, and 09-IMPLEMENTATION.md describe ONLY the actual shipped architecture. A new contributor reading these can build a correct mental model without cross-referencing git log or PROGRESS.md.

## Out of scope

- 01-DATABASE.md and 04-EVENTS.md `ptero_pricing_configs` drift — defer to a separate plan if/when it becomes a problem (deferred from dp-01 closeout for the same reason: those files contain large historical schema definitions that aren't easily rewritten in a focused PR).
- 06-FRONTEND.md, 07-PRICING-MODELS.md — already rewritten by dp-07 + dp-09; verify in passing.
- README.md — already updated by dp-01 closeout.

## Design

### 03-API.md rewrite

Three sections:
1. **Public API** (no change — already accurate; just verify routes match `routes/api.php:18-22, 24-30`).
2. **Admin API** (REWRITE entirely): replace the `AdminController` section with the 4 actual endpoints. Document the `EnsureUserIsAdmin` middleware, the throttle:30,1, and the `Filament panel access` semantic (matches `User::canAccessPanel()`).
3. **Removed endpoints** (NEW small section): one-paragraph note that `validate`, `import`, `export`, `extend`, `cleanup`, `test-connection`, `statistics` previously documented but never shipped or were retired by dp-09 / dp-11. Helps future readers who find references in old commits.

### 05-ADMIN-UI.md rewrite

Replace the Filament resource sections with the actual 4 pages + 2 resources. Each gets a short description (purpose, who uses it, what data source). Drop the Analytics + standalone Settings sections; note SetupWizard replaces them.

### 09-IMPLEMENTATION.md rewrite

Replace the roadmap table with one that reflects what actually shipped:

| Phase | Status | Reference |
|---|---|---|
| Database schema | ✅ Shipped | dp-08 idempotency, dp-08 drop_released migrations |
| Service layer | ✅ Shipped | dp-09 cleanup; 8 services live |
| API endpoints | ✅ Shipped | dp-05 admin API; dp-08 reservation hardening |
| Filament admin UI | ✅ Shipped | dp-13 SetupWizard atomicity |
| Pricing model | ✅ Delegated to core (dp-core-01) | core `DynamicSliderPricingRule` |
| Frontend slider | ✅ Shipped (core dp-10 a11y, dp-core-02 partial) | shared Blade partial |
| Capacity alerts | ✅ Shipped (dp-12) | scheduled task + email |
| Authorization hardening | ✅ Shipped (dp-11) | policy + cart-item ownership |

Plus current backlog reference: `dp-14` through `dp-19` from this audit pass.

## Edits

Three files. Per-file work is mechanical: rewrite the affected sections from scratch (don't try to patch around stale text — too brittle).

## Tests

No tests — docs only.

## Acceptance

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
grep -c 'AdminController\b' 03-API.md       # 0 (only mentioned in "removed endpoints" if at all)
grep -c 'PricingConfigResource' 05-ADMIN-UI.md   # 0
grep -c 'Analytics' 05-ADMIN-UI.md            # 0 OR only in historical reference
# verify each documented controller exists on disk:
for c in AdminReservationController AdminCapacityController; do
  test -f "Http/Controllers/Api/Admin/${c}.php" && echo "OK $c" || echo "MISS $c"
done
```

## Commit

Single commit. Title: `docs: sync 03-API + 05-ADMIN-UI + 09-IMPLEMENTATION with post-dp-13 architecture`.

## Delegation

`task(category="writing", load_skills=["code-review"], run_in_background=true, ...)`. Documentation rewrite category. `code-review` skill loaded so the subagent can self-check for accuracy after edits.

## Status

- [x] Plan written (you are here)
- [x] Delegated to subagent
- [x] 03-API.md rewritten
- [x] 05-ADMIN-UI.md rewritten
- [x] 09-IMPLEMENTATION.md rewritten
- [x] Acceptance grep checks pass
- [x] Commit + push + PR (docs/sync-architecture → PR #19, 82e9115)
- [x] CR review cycle complete (4 rounds; 2 CHANGES_REQUESTED fixed, 2 COMMENTED-only)
- [x] PR merged (f5f88c7, squash into dynamic-slider)
- [x] PROGRESS.md updated (4a5641d)
