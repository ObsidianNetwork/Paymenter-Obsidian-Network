# DynamicPterodactyl — Admin API Routes Implementation

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/`
**Type**: Implement the 3 admin API endpoints currently stubbed at `routes/api.php:35`.

---

## Problem

Audit finding #1. `routes/api.php:35` declares admin routes but the handlers are stubbed or absent. Admin Filament pages (`Dashboard.php`, `NodeMonitoring.php`, `AuditLogPage.php`) currently pull data via direct service calls — works, but means:
- No programmatic access for ops/monitoring tools.
- Any future admin UI (mobile, external dashboard) has no data source.
- Inconsistent with the customer-facing side which uses API + controllers.

---

## Pre-work — scope the actual need

Before writing anything, scope what the stubbed routes were intended to do.

### Step 1: Read
```
routes/api.php (full file, not just line 35)
03-API.md (admin routes section)
Admin/Pages/Dashboard.php (find every service call)
Admin/Pages/NodeMonitoring.php
Admin/Pages/AuditLogPage.php
Admin/Resources/ReservationResource.php
```

### Step 2: Enumerate
Build a table of "what admin-API-worthy data does each page currently fetch directly?":

| Page | Service call | Candidate API endpoint |
|---|---|---|
| Dashboard | `ResourceCalculationService::getLocationAvailability` loop | `GET /admin/capacity` |
| NodeMonitoring | per-node fetches | `GET /admin/nodes/{id}` |
| AuditLogPage | direct Eloquent on `AuditLog` | `GET /admin/audit-logs?filter=...` |
| ReservationResource | Eloquent on `ptero_resource_reservations` | `GET /admin/reservations` |

The spec (`03-API.md`) may already describe these. If so, match its names exactly. If not, propose the table above and code it.

### Step 3: Decide scope
Default scope: the minimum set that lets the admin UI stop doing direct service calls. Likely 3 endpoints:
1. **Admin reservations list** (paginated, filterable by status/location/node).
2. **Admin reservation cancel** (by id/token, with audit trail).
3. **Admin capacity summary** (all locations + nodes in one call, for dashboard).

Confirm count matches `routes/api.php:35` stubs. If stubs suggest different endpoints, follow stubs.

---

## Design

### Route group
```php
Route::middleware(['web', 'auth', 'admin'])
    ->prefix('extensions/dynamic-pterodactyl/admin')
    ->group(function () {
        Route::get('reservations', [AdminReservationController::class, 'index']);
        Route::post('reservations/{token}/cancel', [AdminReservationController::class, 'cancel']);
        Route::get('capacity', [AdminCapacityController::class, 'summary']);
    });
```

### Controllers
Create two (or extend the customer controllers — NO, keep admin separate for clarity):

`Http/Controllers/Api/Admin/AdminReservationController.php`:
```php
public function index(Request $request): JsonResponse
{
    $filters = $request->validate([
        'status' => 'nullable|in:pending,confirmed,cancelled,expired',
        'location_id' => 'nullable|integer',
        'node_id' => 'nullable|integer',
        'user_id' => 'nullable|integer',
        'per_page' => 'nullable|integer|min:1|max:100',
    ]);
    // Paginate explicitly — do NOT return unbounded collections.
    $query = app(ReservationService::class)->queryAll($filters);
    return response()->json([
        'success' => true,
        'data' => $query->paginate($filters['per_page'] ?? 25),
    ]);
}

public function cancel(string $token, Request $request): JsonResponse
{
    $reason = $request->validate(['reason' => 'required|string|max:255'])['reason'];
    $ok = app(ReservationService::class)->cancel($token, $reason, isAdminAction: true);
    return response()->json([
        'success' => $ok,
        'message' => $ok ? 'Reservation cancelled' : 'Reservation not pending or not found',
    ], $ok ? 200 : 404);
}
```

`Http/Controllers/Api/Admin/AdminCapacityController.php`:
```php
public function summary(): JsonResponse
{
    // Read Pterodactyl once — use existing services.
    $locations = app(ResourceCalculationService::class)->getLocations();
    $summary = array_map(fn ($loc) => [
        'location' => $loc,
        'availability' => app(ResourceCalculationService::class)->getLocationAvailability($loc['id']),
    ], $locations);

    return response()->json(['success' => true, 'data' => $summary]);
}
```

### Add missing `ReservationService::queryAll`
The existing `ReservationService::getAll()` returns a collection, not a paginatable query. Either:
- Rename + change return type to `Builder` — risky, callers may depend on collection.
- Add a parallel `queryAll()` that returns the builder — safer.

Pick the second. One method, one commit, no surprises.

### Response shape
Match existing customer controllers (audit confirmed consistency). Every endpoint:
- `{"success": bool, "data": ...}` on success
- `{"success": false, "message": "...", "error": "..."}` on failure
- Standard HTTP codes: 200/201/204, 400/401/403/404, 422, 500

### Auth
- Route group middleware `['web', 'auth', 'admin']` — existing pattern.
- No per-method re-auth; admin middleware is enough.

### Input validation
- Use `$request->validate(...)` inline with FormRequest pattern only if validation is reused. For these endpoints, inline is fine.
- Explicit `per_page` max prevents DB-hammering.

### Rate limiting
- Admin routes don't need throttling — admins are trusted and low-volume. Skip.

---

## Exact changes

1. `routes/api.php` — replace the stub block at line 35 with the real route group above. Remove the `// TODO` markers.
2. Create `Http/Controllers/Api/Admin/AdminReservationController.php`.
3. Create `Http/Controllers/Api/Admin/AdminCapacityController.php`.
4. Add `Services/ReservationService::queryAll()` returning a `Builder`.
5. Migrate `Admin/Pages/ReservationResource.php` (and any others) to call the new API endpoints OR continue calling the service directly — **decision**: leave existing Filament pages alone. The API is for external consumers + future internal rewrites. Filament resources calling services directly is fine.

---

## Testing

### Feature tests
Create `tests/Feature/AdminApiTest.php` (if the extension's phpunit config supports feature tests; confirm first — it uses SQLite memory so it should).

1. `test_index_returns_paginated_reservations`
2. `test_index_respects_status_filter`
3. `test_index_rejects_non_admin` — 403
4. `test_cancel_flips_pending_to_cancelled_and_audits`
5. `test_cancel_of_nonexistent_token_returns_404`
6. `test_cancel_requires_reason`
7. `test_capacity_summary_hits_pterodactyl_and_aggregates` — use `Http::fake()`

Use existing `LaravelTestCase` base.

### Manual smoke
```bash
# As admin user (use a token or session cookie)
curl -H "Cookie: XSRF-TOKEN=..." https://paymenter.test/extensions/dynamic-pterodactyl/admin/reservations
curl -H "Cookie: ..." -X POST -d '{"reason":"test"}' -H "Content-Type: application/json" \
  https://paymenter.test/extensions/dynamic-pterodactyl/admin/reservations/abc/cancel
curl https://paymenter.test/extensions/dynamic-pterodactyl/admin/capacity
```

---

## Risks

| Risk | Mitigation |
|---|---|
| Spec in `03-API.md` disagrees with stubbed routes and disagrees with this plan | Read spec first, match it exactly if plausible. If spec is stale, update it in the doc-refresh plan (`dp-01`). Don't ship API that disagrees with docs. |
| `admin` middleware doesn't exist in Paymenter core | Verify with `grep -rn "'admin'" /var/www/paymenter/bootstrap/app.php app/Http/Kernel.php`; otherwise use whatever Paymenter uses (could be `auth:admin` guard or a policy). |
| Pagination breaks existing `getAll()` callers | Introduce `queryAll()` as a new method, leave `getAll()` untouched. |
| Cancel endpoint races with customer cancel | Current `cancel()` is idempotent on status — safe. |
| Capacity endpoint loads too many nodes | Reuse existing `getLocationAvailability` logic which filters by location. For many-location deployments, add `?location_id=...` filter. Scope decision — default to all; filter param optional. |

---

## Acceptance

- Three endpoints responding correctly (unit + manual smoke).
- `routes/api.php:35` stub markers removed.
- `03-API.md` matches what's implemented (update if needed).
- Admin middleware enforced: non-admin gets 403.
- Pagination works and caps out at 100/page.
- `queryAll()` exists in `ReservationService` without breaking `getAll()`.

---

## Commit

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git add -A
git commit -m "feat(admin-api): implement admin reservation and capacity endpoints"
```

---

## Delegation

`task(category="deep", load_skills=[], run_in_background=true, ...)`

Branch setup (run before delegating):

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git fetch origin
git checkout -b dp-05-admin-api-routes origin/dynamic-slider
```

Agent MUST:
1. Read `routes/api.php` and the stub block at line 35 VERBATIM first. If the stubbed handlers hint at different intended names/verbs, follow the stubs over this plan's defaults.
2. Read `03-API.md`'s admin routes section. Match it where plausible.
3. Verify `admin` middleware exists in Paymenter core or adapt.
4. Implement controllers, add `queryAll()`, wire routes.
5. Write feature tests.
6. Commit.

Publish for review:

```bash
git push -u origin dp-05-admin-api-routes
gh pr create --base dynamic-slider --title "feat(admin-api): implement admin reservation and capacity endpoints" --fill
```

---

## Out of scope

- Migrating Filament pages to consume the new API — intentional. Filament-direct-service-calls are fine; API is for external consumers.
- Adding `PUT /reservations/{token}/extend` admin endpoint — add if `routes/api.php:35` stubs include it, otherwise defer.
- Bulk cancel, bulk stats, export endpoints — later.
- API auth token system — current cookie-based admin session is sufficient.
