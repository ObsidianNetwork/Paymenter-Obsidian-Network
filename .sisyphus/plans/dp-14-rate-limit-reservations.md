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

Add a single Feature test to `tests/Feature/ReservationApiTest.php` named
`test_reservation_create_throttles_at_10_per_minute`.

**Critical**: do NOT use `createConfiguredProduct()` for this test. The fixture
helpers in this file have known pre-existing failures when the request actually
reaches `ReservationService::create()` (see
`.sisyphus/notepads/dp-14-rate-limit-reservations/problems.md`). Use the same
pattern as the existing passing `test_store_rejects_unconfigured_product`:

- Fresh `User::factory()` per test guarantees a unique throttle key (no cross-test bleed).
- Bare `Product::factory()->create()` (no slider config) yields deterministic 422 from
  `StoreReservationRequest` validation without touching `ReservationService`,
  `NodeSelectionService` mocks, or the `ptero_resource_reservations` table.
- Reuse the existing `createCartItemForUser()` helper for one cart item.
- Pipeline order is `web -> auth -> throttle -> controller -> FormRequest`,
  so each 422 still increments the throttle counter. 10 POSTs return 422; the 11th must be 429.

Reference skeleton (place before the `private function createConfiguredProduct()` declaration):

```php
public function test_reservation_create_throttles_at_10_per_minute(): void
{
    $user = User::withoutEvents(fn () => User::factory()->create());
    /** @var Product $product */
    $product = Product::factory()->create();
    $cartItemId = $this->createCartItemForUser($user, $product->id);

    $payload = [
        'product_id'   => $product->id,
        'location_id'  => 1,
        'memory'       => 4096,
        'cpu'          => 200,
        'disk'         => 51200,
        'cart_item_id' => $cartItemId,
    ];

    for ($i = 1; $i <= 10; $i++) {
        $this->actingAs($user)
            ->postJson('/api/dynamic-pterodactyl/reservation', $payload)
            ->assertStatus(422);
    }

    $this->actingAs($user)
        ->postJson('/api/dynamic-pterodactyl/reservation', $payload)
        ->assertStatus(429);
}
```

**Pre-existing failures are OUT OF SCOPE for dp-14.** The 7 errors and 3 failures
already present in `tests/Feature/ReservationApiTest.php` (table missing, unrelated
validation 422s) must NOT be touched in this PR. File a follow-up plan
(e.g. `dp-20-test-infra-migrations`) if cleanup is desired.

## Acceptance

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl

# 1. Route is throttled
grep -A2 'Reservation endpoints' routes/api.php
# Expect: comment containing "throttled (10 req/min)" AND throttle:10,1 in middleware array

# 2. Spec doc reflects new behaviour
grep -A1 '### POST /api/dynamic-pterodactyl/reservation' 03-API.md
# Expect: "Create a resource reservation. **Throttled at 10 req/min per authenticated user.**"
grep -B1 -A2 'throttle:10,1' 03-API.md
# Expect: a separate reservation route group block in the code snippet

# 3. New throttle test passes in isolation
../../../vendor/bin/phpunit tests/Feature/ReservationApiTest.php \
  --filter test_reservation_create_throttles_at_10_per_minute
# Expect: 1 test, 11 assertions, 0 failures, 0 errors

# 4. Pre-existing failure baseline unchanged (must not REGRESS)
../../../vendor/bin/phpunit tests/Feature/ReservationApiTest.php
# Expect: Tests: 15, Errors: 7, Failures: 3 (the 4 pre-existing pass + the new throttle test pass)
```

## Commit

Single commit. Title: `fix(security): restore documented throttle on reservation endpoints (10/min)`.

## Delegation

Spawn a single implementation subagent. Suggested invocation:

```
task(
  category="quick",
  load_skills=["code-review", "autofix"],
  run_in_background=true,
  prompt=<<below>>
)
```

Subagent prompt:

> Branch `dp-14-rate-limit-reservations` is already created off `dynamic-slider`
> in `/var/www/paymenter/extensions/Others/DynamicPterodactyl/`. The first two
> file changes are already in the working tree (verify via the Acceptance grep
> commands): `routes/api.php` (throttle:10,1 + updated comment) and `03-API.md`
> (split route group + appended throttle note on POST description). Only the
> test addition is outstanding, and it MUST follow the unconfigured-product
> pattern documented in the Tests section of
> `.sisyphus/plans/dp-14-rate-limit-reservations.md`.
>
> Steps:
> 1. `cd /var/www/paymenter/extensions/Others/DynamicPterodactyl` and confirm
>    `git branch --show-current` is `dp-14-rate-limit-reservations`.
> 2. Read `.sisyphus/notepads/dp-14-rate-limit-reservations/{problems,learnings,status}.md`.
>    Do NOT try to fix the pre-existing 7 errors / 3 failures.
> 3. Open `tests/Feature/ReservationApiTest.php`. If a previous attempt left a
>    broken `test_reservation_create_throttles_at_10_per_minute` method (using
>    `createConfiguredProduct()`), REPLACE it with the skeleton in the plan's
>    Tests section. Otherwise insert it just before the `private function
>    createConfiguredProduct()` declaration.
> 4. Run all four Acceptance commands. All must pass.
> 5. Run `coderabbit review --plain --type uncommitted` for pre-commit self-check on the working-tree changes.
>    Address any findings before staging. Re-run until clean (0 actionable findings).
> 6. Stage exactly three files: `git add routes/api.php 03-API.md tests/Feature/ReservationApiTest.php`.
> 7. `git commit -m "fix(security): restore documented throttle on reservation endpoints (10/min)"`.
> 8. `git push -u origin dp-14-rate-limit-reservations`.
> 9. `gh pr create --base dynamic-slider --title "fix(security): restore documented throttle on reservation endpoints (10/min)"`
>    body referencing dp-audit-2026-04-26 finding F1.
> 10. Run `.sisyphus/templates/ralph-loop-verify.sh` until merge-state is CLEAN with 0 unresolved threads.
>     (Pre-commit `coderabbit review --plain --type uncommitted` already ran in Step 5; the PR auto-review on Step 9 covers committed-state findings.)
> 11. Squash-merge the PR. Confirm `gh pr view --json state -q .state` returns `MERGED`.
> 12. Update plan Status block (mark items complete) and report back.

## Status

- [x] Plan written
- [x] Plan refined with concrete unconfigured-product test pattern (notepad: dp-14-rate-limit-reservations)
- [x] Delegated to subagent (`bg_8d284416` / `ses_235ca1e19ffe0sMLGaLxwGCYJP`, Sisyphus-Junior, category=deep)
- [x] Edit + test + doc update + commit + push + PR (commit `4a9f50d`, PR https://github.com/Jordanmuss99/dynamic-pterodactyl/pull/17)
- [x] CR review cycle complete (`reviewDecision: APPROVED`, `mergeStateStatus: CLEAN`)
- [x] PR merged on `dynamic-slider` (squash `5b13f774e3a195b07dc687c10e7f90b3ee26fab9`, merged 2026-04-26T14:45:21Z)
- [x] PROGRESS.md updated to mark this plan shipped (commit `710cf45`)
