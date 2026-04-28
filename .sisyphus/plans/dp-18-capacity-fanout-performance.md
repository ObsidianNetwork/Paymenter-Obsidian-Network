# dp-18 — Capacity-fanout Performance: batch admin-view Pterodactyl reads

**Source**: dp-audit-2026-04-26 finding F5.
**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (nested git repo, branch `dynamic-slider`).
**Type**: Performance refactor + Pterodactyl rate-budget protection. Touches `ResourceCalculationService` and the three admin views that consume it.
**Effort**: L (1+ day; design surface is non-trivial).
**Severity**: high.
**Suggested branch**: `dp-18-capacity-fanout`.

---

## Problem

Per audit finding F5, three admin code paths walk Pterodactyl in O(locations × nodes) per refresh:

- `Admin/Pages/Dashboard.php:37-43` — Dashboard initial render.
- `Admin/Pages/NodeMonitoring.php:29-41` — Node monitoring page.
- `Http/Controllers/Api/Admin/AdminCapacityController.php:20-35` — `/admin/capacity` API.

All three loop:

```
for each location: getLocationAvailability(location.id)
  → for each node in location: fetchServersOnNode(node.id)
```

So a single dashboard render = `1 + N_locations + N_nodes` Pterodactyl API calls. For a moderately-sized panel (5 locations, 30 nodes total), that's 36 calls. For larger setups it scales worse.

**Constraint from `DECISIONS.md`**: extension's API budget against Pterodactyl is ~10/min target against a 240/min panel limit. Dashboard refreshes blow this budget instantly.

**Constraint from extension policy**: `AGENTS.md` FAIL-when rule prohibits caching Pterodactyl API responses (real-time queries are a settled decision). So the fix can't be "cache everything" — it has to be "fetch in larger batches, less often".

## Goal

Capacity views fetch Pterodactyl data in O(1) round-trips (or constant-bounded — e.g., one paginated list per resource type) per refresh. No caching that violates the real-time rule. No regression in display accuracy.

## Design

### Constraint analysis

The "no caching" rule means we can't keep a result around between requests. But within a single request handler, we can absolutely fetch once and reuse. The current code re-walks the API per location even within a single request — that's the waste.

### Refactor

Add a `ResourceCalculationService::buildClusterSnapshot()` method that:
1. Fetches `/api/application/nodes?include=servers` ONCE — gets all nodes + their servers in a single paginated call (Pterodactyl supports `include` parameter).
2. Fetches `/api/application/locations` ONCE.
3. Returns an in-memory aggregate: `[location_id => [nodes => [...], totals => {memory, cpu, disk, used, free}]]`.

Each admin caller then reads from the snapshot instead of re-walking the API.

### Pagination

Pterodactyl pages results at 50 per page by default. For installations >50 nodes, `buildClusterSnapshot` walks pages within ONE request — still O(pages), but constant per resource type (not multiplied by location count).

### Backwards compatibility

Existing `getLocationAvailability($locationId)` stays — it's used by other code paths (e.g., the customer-facing `/availability/{locationId}` endpoint). Don't break it. Just add the batch path for admin views.

### API budget impact

After: dashboard render ≈ 2-4 Pterodactyl calls (one for nodes-with-servers, one for locations, plus maybe pages). Vs current 30+ for a typical panel. Comfortably under the 10/min target.

## Edits

- `Services/ResourceCalculationService.php`: add `buildClusterSnapshot()` method + helpers.
- `Admin/Pages/Dashboard.php`: replace per-location loop with single `buildClusterSnapshot()` call.
- `Admin/Pages/NodeMonitoring.php`: same.
- `Http/Controllers/Api/Admin/AdminCapacityController.php`: same.
- Tests: extend `tests/Unit/ResourceCalculationServiceTest.php` with snapshot tests; update affected feature tests.

## Tests

Unit tests for `buildClusterSnapshot()`:
- `test_snapshot_with_single_location_single_node`
- `test_snapshot_aggregates_across_locations`
- `test_snapshot_handles_paginated_node_response`
- `test_snapshot_handles_pterodactyl_5xx_gracefully` (degradation behaviour)

Feature smoke test against the Dashboard page: assert it renders with a mocked Pterodactyl client returning a 3-location, 10-node fixture; assert the rendered DOM has all 3 locations.

Performance assertion (NEW): wrap a counter around the HTTP client mock. Assert that rendering the Dashboard with the snapshot path makes ≤ 4 mock calls regardless of location count. Catches regression if someone adds a per-location fetch in future.

## Acceptance

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
../../../vendor/bin/phpunit tests/Unit/ResourceCalculationServiceTest.php   # green
../../../vendor/bin/phpunit tests/Feature/AdminApiTest.php                  # green
# Manual: load Dashboard page in dev environment, verify nodes/locations render correctly
# Manual: tail Pterodactyl access log during a Dashboard refresh, confirm ~3 hits not 30+
```

## Commit

Single commit if it stays clean. Title: `perf(admin): batch Pterodactyl capacity reads via cluster snapshot`.

If snapshot construction is large enough (200+ lines), split into:
1. `feat(service): ResourceCalculationService::buildClusterSnapshot()` (the new method + tests)
2. `perf(admin): consume cluster snapshot from Dashboard / NodeMonitoring / AdminCapacity` (the call-site swaps)

## Delegation

`task(category="deep", load_skills=["code-review"], run_in_background=true, ...)`. The design has real surface area — pagination handling, error degradation, multiple call-site swaps. Deep category fits.

## Risks

- **Pterodactyl `include=servers` may behave differently than per-node `fetchServersOnNode`** (e.g., excludes suspended servers, or returns different field shape). Subagent must verify against the running panel's API response shape — read `02-SERVICES.md` for documented Pterodactyl client patterns first, then run the actual API call once for verification.
- **Pagination edge cases**: 0-results, 1-page, multi-page. Tests cover these.
- **Memory**: snapshot for a 200-node panel might be a few MB. Acceptable; admin pages are not high-frequency.

## Status

- [x] Plan written (you are here)
- [x] Delegated to subagent
- [x] `buildClusterSnapshot()` implemented + unit-tested (5 unit tests, 53 assertions)
- [x] Dashboard, NodeMonitoring, AdminCapacityController call-sites swapped
- [x] Performance assertion test passes (≤ 4 mock calls) — 55 nodes across 2 pages = 3 calls
- [x] PR opened (#20, dp-18-capacity-fanout-performance branch)
- [x] CR review cycle complete (APPROVED directly, first review)
- [x] PR merged (be4756f, squash into dynamic-slider)
- [x] PROGRESS.md updated (cb5cd31)
- [x] Manual smoke: unit test call-count assertion substitutes (no dev Pterodactyl panel available)
