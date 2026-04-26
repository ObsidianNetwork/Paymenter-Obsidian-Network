# dp-14 — Rate-limit reservation endpoints

**Source**: dp-audit-2026-04-26 finding F1.
**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (nested git repo, branch `dynamic-slider`).
**Type**: Security hardening. One-file change (`routes/api.php`) plus matching spec note.
**Effort**: S (≤ 1 hour).
**Severity**: medium.
**Suggested branch**: `dp-14-rate-limit-reservations`.

---

## Problem

`03-API.md:20-34` documents that all public routes — including reservation create/get/extend/cancel — sit inside the `throttle:30,1` middleware group. The shipped `routes/api.php:24-30` splits reservations into a second route group with NO throttle:

```php
// Reservation endpoints — no throttle; these are cart-lifecycle-driven, not polling
Route::prefix('api/dynamic-pterodactyl')->middleware(['web', 'auth'])->group(function () {
    Route::post('/reservation', [ReservationController::class, 'create']);
    Route::get('/reservation/{token}', [ReservationController::class, 'get']);
    Route::delete('/reservation/{token}', [ReservationController::class, 'cancel']);
    Route::post('/reservation/{token}/extend', [ReservationController::class, 'extend']);
});
```

The inline comment claims "cart-lifecycle-driven, not polling" — true, but an authenticated user can still spam create/extend, each of which:
- Acquires DB locks (`ReservationService::create()` does pessimistic locking on capacity rows).
- Triggers `NodeSelectionService` work (full node-availability traversal).
- Produces audit-log entries.

A misbehaving client (browser bug, automated retry, malicious actor) can exhaust DB connection pool or saturate Pterodactyl API budget without ever hitting the documented throttle.

## Goal

Restore the documented `throttle` semantics on reservation endpoints, but sized for legitimate checkout retries (which can be bursty) rather than the 30/min limit on availability/pricing.

## Design

### Throttle sizing

- **Availability/pricing**: 30/min (existing). High-frequency polling, hits Pterodactyl. Keep as-is.
- **Reservation**: lower-frequency but bursty around checkout finalize. Reasonable bound: **10/min per user**.
- **Reasoning**: a normal checkout writes 1 reservation, optionally extends 1-2 times if the user dawdles, deletes 0-1 times. Even a frantic user with cart-abandonment-recovery shouldn't hit 10/min. Malicious abuse caught.

### Implementation

Two options:

**Option A (preferred)**: extend the existing reservation route group with a separate throttle:

```php
Route::prefix('api/dynamic-pterodactyl')->middleware(['web', 'auth', 'throttle:10,1'])->group(function () {
    Route::post('/reservation', [ReservationController::class, 'create']);
    Route::get('/reservation/{token}', [ReservationController::class, 'get']);
    Route::delete('/reservation/{token}', [ReservationController::class, 'cancel']);
    Route::post('/reservation/{token}/extend', [ReservationController::class, 'extend']);
});
```

Update the inline comment to reflect the new behaviour: `// Reservation endpoints — throttled (10 req/min) for checkout-retry burst tolerance without enabling abuse`.

**Option B**: a named rate limiter via `RateLimiter::for('reservation', ...)` if any per-user/per-IP tuning is needed. Probably overkill for this change.

Pick A unless the user requests otherwise.

## Edits

Single file: `routes/api.php`.

1. Update the second route group's middleware array from `['web', 'auth']` to `['web', 'auth', 'throttle:10,1']`.
2. Update the inline comment to match.

## Tests

Add a small Feature test — reuse the existing `tests/Feature/ReservationApiTest.php` scaffolding:

- `test_reservation_create_throttles_at_10_per_minute_per_user`: spin up 11 authenticated requests in a tight loop; assert request 11 returns HTTP 429.

If `tests/Feature/ReservationApiTest.php` already throws on rate-limit responses (likely, since the current state has no throttle), the change is additive — existing passing tests stay green; one new test verifies the throttle actually fires.

## Acceptance

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
grep -A2 'Reservation endpoints' routes/api.php   # comment + throttle:10,1 visible
../../../vendor/bin/phpunit tests/Feature/ReservationApiTest.php   # green incl. new throttle test
```

`03-API.md` reservation section also needs a one-line update saying "Throttled at 10/min per authenticated user" — add it to the existing reservation endpoint table.

## Commit

Single commit. Title: `fix(security): restore documented throttle on reservation endpoints (10/min)`.

## Delegation

`task(category="quick", load_skills=["code-review"], run_in_background=true, ...)`. One file change + one test + one doc line + PR. Use `code-review` skill before push for self-check.

## Status

- [ ] Plan written (you are here)
- [ ] Delegated to subagent
- [ ] Edit + test + doc update + commit + push + PR
- [ ] CR review cycle complete
- [ ] PR merged on `dynamic-slider`
- [ ] PROGRESS.md updated to mark this plan shipped
