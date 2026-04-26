# DynamicPterodactyl — Reservation Verification + Idempotency Correctness

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/`
**Type**: Fix four correctness defects in the reservation subsystem that allow valid payments to be rejected, duplicate holds to persist, out-of-bounds reservations to succeed, and misleading "has capacity" signals to reach customers.

---

## Problem

The audit (sessions `ses_24c9dae2dffeSpxiEGlGCd5B4C` + `ses_24c9d4dd8ffecFFWJ2Uf4wI0Eb`) found four release-blocking defects in the reservation path. Each is independently reproducible and customer-visible.

### Defect 1 — Payment-time verification subtracts the reservation from itself

**Evidence:**
- `Services/ResourceCalculationService.php:83-96` — `getLocationAvailability()` returns `available = capacity - sum(pending reservations)`.
- `Services/ResourceCalculationService.php:170-183` — `verifyAvailability()` calls the above with no exclusion.
- `Listeners/InvoicePaidListener.php:58-95` — at payment time, re-runs `verifyAvailability()` on the reservation being confirmed. The pending reservation is still pending, so its own resources are subtracted from `capacity`. A reservation that *exactly fits* the remaining capacity fails verification because the math says `available < requested`.

**Failure mode:** any reservation that exactly consumes the last block of a resource fails payment confirmation and triggers the shortfall alert path. Customer sees a failed order despite paying.

### Defect 2 — No idempotency on reservation writes

**Evidence:**
- `Http/Controllers/Api/ReservationController.php:18-56` — `store()` accepts no idempotency key.
- `Services/ReservationService.php:84-125` — `create()` always inserts a new row, no dedup by cart item or client token.

**Failure mode:** double-clicks, network retries, and checkout component rerenders each create a fresh pending hold. The same cart item can hold resources 3–5× over before any single hold expires. Multi-user checkouts are starved of capacity that's actually available.

### Defect 3 — Reservation API bypasses slider bounds

**Evidence:**
- `Http/Controllers/Api/ReservationController.php:20-40` — `store()` validates only that the resource ints are non-negative; accepts any value.
- `Services/ReservationService.php:75-103` — `create()` persists whatever was sent.
- `Services/PricingCalculatorService.php:29-36, 187-221` — if no slider config exists for the product, pricing returns `{model: 'none', total: 0}` and reservation still persists.

**Failure mode:** authenticated users can reserve arbitrary resources the product was never configured for. Zero-priced reservations persist when a product has no slider config at all. No integrity constraint anywhere prevents "reserve 999GB RAM on a product that only exposes a 1-8GB slider".

### Defect 4 — `has_capacity` true when memory > 0 even if CPU/disk exhausted

**Evidence:**
- `Http/Controllers/Api/AvailabilityController.php:31-36` — `summary()` returns `has_capacity` based on a single-resource threshold.

**Failure mode:** frontend shows "this location has capacity" when only memory is available but CPU/disk are exhausted. Customer starts the checkout flow, gets through pricing, reserves successfully, then payment verification (Defect 1 aside) actually fails at allocation.

---

## Design

Four independent fixes on a single branch. Each has its own commit for reviewability.

### Fix 1 — Self-exclusion in availability math

**Files:** `Services/ResourceCalculationService.php`, `Services/ReservationService.php`, `Listeners/InvoicePaidListener.php`

**Change:**
- Add optional `?string $excludeReservationToken = null` parameter to:
  - `ResourceCalculationService::getLocationAvailability()`
  - `ResourceCalculationService::verifyAvailability()` (or equivalent — confirm exact name at read time)
- When summing pending reservations, skip the row whose `token` matches `$excludeReservationToken`.
- Callers at confirmation time (`InvoicePaidListener`, any `ReservationService::confirm()` path) pass the token of the reservation being confirmed.
- Callers at creation time pass `null` — new holds compete against all existing holds.

**Invariant:** a reservation can never make itself fail availability math at confirmation time. A reservation's own resources are treated as "already allocated to this request" for its own confirmation check, not as "additional competing demand".

### Fix 2 — Idempotency key on reservation create

**Files:** new migration, `Http/Controllers/Api/ReservationController.php`, `Services/ReservationService.php`, `Models/ResourceReservation.php` (or equivalent)

**Change:**
- **Migration** `2026_04_23_000001_add_idempotency_key_to_ptero_resource_reservations.php`:
  - Add `idempotency_key VARCHAR(64) NULL` column.
  - Add partial unique index on `idempotency_key` where `status IN ('pending', 'confirmed')` — so the same key can be reused after cancellation/expiry. If the DB doesn't support partial unique indexes (MySQL does via filtered indexes in 8.0.13+; confirm version), use a composite unique on `(idempotency_key, user_id)` and check status at the app layer instead.
  - Down migration drops the index + column.
- **Controller**: `store()` reads `Idempotency-Key` header (preferred) or `idempotency_key` body field. Validate: length 8–64 chars, alphanumeric + hyphen.
- **Service**: `create()` accepts `?string $idempotencyKey`. If set:
  - Look up existing reservation by `idempotency_key + user_id` where status ∈ {`pending`, `confirmed`}.
  - If found and still valid: return existing row, do NOT create a new one. Log at INFO level.
  - If found but expired/cancelled: proceed with new creation (the unique index allows it because partial filter excludes terminal states).
  - If not found: create + persist key.
- Document the header in `03-API.md`.

**Invariant:** same idempotency key + same user + active state ⇒ same reservation. Double-click, retry, and rerender are safe.

### Fix 3 — Validate selected resources against product config

**Files:** new `Http/Requests/StoreReservationRequest.php`, `Http/Controllers/Api/ReservationController.php`, maybe a small helper in `Services/`

**Change:**
- Create a Laravel FormRequest `StoreReservationRequest` that:
  - Validates basic shape (memory_mb, cpu_percent, disk_mb are non-negative ints).
  - Looks up the product's `dynamic_slider` config options via the `product_id` in the request.
  - For each configured slider, validates the selected value is:
    - Between the slider's `min` and `max` inclusive
    - A valid step multiple (if `step > 1`)
  - If the product has NO dynamic_slider config options: reject with 422 `"This product is not configured for dynamic reservations"`.
  - If a required resource (memory/cpu/disk) is missing from the request but present in config: reject with 422.
  - If an extra resource is in the request but not in config: reject with 422.
- `ReservationController::store()` type-hints `StoreReservationRequest` — Laravel runs validation automatically.
- The FormRequest's `authorize()` method can also be where we check the user owns the cart/order context (future hardening, not required here).

**Invariant:** you cannot create a reservation for a product that isn't configured, nor for values outside the product's declared slider bounds.

### Fix 4 — Strict `has_capacity` logic

**Files:** `Http/Controllers/Api/AvailabilityController.php`

**Change:**
- `summary()` computes `has_capacity` as `memory_mb > 0 AND cpu_percent > 0 AND disk_mb > 0`.
- Add a new per-resource field in the response: `resource_capacity: { memory: bool, cpu: bool, disk: bool }` so the frontend can show which specific resource is exhausted if needed.
- Keep `has_capacity` as the conservative "can actually provision" signal.

**Invariant:** `has_capacity: true` means all three critical resources are non-zero.

---

## Testing

### Unit

- **`tests/Unit/ResourceCalculationServiceTest.php`** (new or extend existing):
  - `test_get_location_availability_excludes_given_reservation_token`: seed two pending reservations, call with one's token, assert its resources are NOT subtracted.
  - `test_verify_availability_with_self_exclusion_succeeds_on_edge_fit`: reservation exactly fits remaining capacity, verification with self-exclusion passes.
  - `test_verify_availability_without_exclusion_fails_on_edge_fit` (regression-proving the bug): same setup, no exclusion, verification fails.

- **`tests/Unit/ReservationServiceTest.php`** (extend):
  - `test_create_with_idempotency_key_returns_existing_on_duplicate`.
  - `test_create_with_idempotency_key_creates_new_after_original_cancelled`.
  - `test_create_without_idempotency_key_always_creates_new`.

- **`tests/Unit/StoreReservationRequestTest.php`** (new):
  - Data-provider-driven: valid request passes; out-of-bounds memory rejected; wrong step rejected; missing required resource rejected; extra resource rejected; product without slider config rejected.

### Feature

- **`tests/Feature/ReservationApiTest.php`** (new or extend):
  - `test_store_rejects_unconfigured_product`: 422.
  - `test_store_rejects_out_of_bounds_memory`: 422.
  - `test_store_with_idempotency_key_returns_same_reservation_on_retry`: two POSTs with same key → one row in DB, same token returned.
  - `test_store_without_idempotency_key_creates_fresh_each_time`: two POSTs without keys → two distinct reservations.
  - `test_confirmation_passes_when_reservation_exactly_fits`: full end-to-end: create reservation → mock invoice paid event → reservation transitions to confirmed (not failed).

- **`tests/Feature/AvailabilityApiTest.php`** (new or extend):
  - `test_has_capacity_false_when_cpu_exhausted_but_memory_positive`: seed mock where memory=1000, cpu=0, disk=1000 → `has_capacity: false`, `resource_capacity: { memory: true, cpu: false, disk: true }`.
  - `test_has_capacity_true_when_all_resources_positive`.

### Manual smoke

1. Configure a product with small (memory=100MB) slider bounds. Reserve exactly 100MB. Trigger invoice-paid webhook. Assert reservation reaches `confirmed`, not `failed`.
2. Double-click checkout form with the browser network tab → confirm only one `ptero_resource_reservations` row.
3. POST `/api/dynamic-pterodactyl/reservations` with `memory_mb: 99999` → 422.
4. POST `/api/dynamic-pterodactyl/reservations` for a product without slider config → 422.
5. Hit `/api/dynamic-pterodactyl/availability/{id}` when Pterodactyl shows 0 free CPU but free memory → `has_capacity: false`.

---

## Risks

| Risk | Mitigation |
|---|---|
| Self-exclusion token refactor changes function signatures used in other places | Grep all callers of `getLocationAvailability` before the change; update in one commit. Deprecation note: prefer named parameter or second-method if legacy callers can't pass token. |
| Partial unique index unsupported on older MySQL | Pre-check server version; fall back to composite unique on `(idempotency_key, user_id)` + app-layer status check. Document in migration comment. |
| FormRequest breaks existing Livewire callers that don't send a full payload | Frontend currently calls the reservation API via an authenticated XHR. Grep for all callers in `themes/` and `resources/`; update any stale payloads. |
| Idempotency key stored in DB without TTL could grow unbounded | `idempotency_key` is on the same row as the reservation; when reservation is cleaned up (expired/cancelled), the key goes with it. No separate table. |
| Existing pending reservations become "invalid" under new FormRequest rules | Migration is additive (new column, new validator). Existing rows are untouched. FormRequest only runs on new creates. |
| CodeRabbit flags the exclusion parameter as "API design smell" | Accept the comment or reject with clear reasoning: exclusion is a narrow concern specific to self-verification; leaking it into public API preserves testability. |

---

## Acceptance

- `verifyAvailability()` accepts a token-to-exclude and skips it; feature test proves edge-fit reservations confirm successfully.
- `ptero_resource_reservations` has `idempotency_key` column with the right index; `ReservationController::store()` reads the header; repeated POSTs with the same key return the same reservation.
- `StoreReservationRequest` exists, wired into the controller, and rejects unconfigured products + out-of-bounds values.
- `AvailabilityController::summary()` computes `has_capacity` from all three resources; response includes `resource_capacity` per-resource booleans.
- `phpunit` green from inside `extensions/Others/DynamicPterodactyl/`.
- No regression in existing reservation, cart, checkout, or invoice tests.
- Manual smoke tests (1–5 above) all pass.

---

## Commit

One commit per fix for reviewability:

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl

# Fix 1
git add Services/ResourceCalculationService.php Services/ReservationService.php Listeners/InvoicePaidListener.php tests/Unit/ResourceCalculationServiceTest.php tests/Feature/ReservationApiTest.php
git commit -m "fix(reservation): exclude self from availability math at confirmation time"

# Fix 2
git add database/migrations/*add_idempotency_key* Http/Controllers/Api/ReservationController.php Services/ReservationService.php Models/ResourceReservation.php tests/Unit/ReservationServiceTest.php tests/Feature/ReservationApiTest.php
git commit -m "feat(reservation): idempotency-key support on create endpoint"

# Fix 3
git add Http/Requests/StoreReservationRequest.php Http/Controllers/Api/ReservationController.php tests/Unit/StoreReservationRequestTest.php tests/Feature/ReservationApiTest.php
git commit -m "fix(reservation): validate selected resources against product slider bounds"

# Fix 4
git add Http/Controllers/Api/AvailabilityController.php tests/Feature/AvailabilityApiTest.php
git commit -m "fix(availability): require all resources positive for has_capacity true"
```

Also update `03-API.md` if payload shape or headers changed; and `CHANGELOG.md`.

---

## Delegation

Category: `deep`. Single subagent executes all four fixes sequentially on one branch. Reason: Fix 3's FormRequest pulls in the same controller that Fix 2 modifies; Fix 1's signature change propagates to the InvoicePaidListener tests that Fix 3 extends. Splitting forces extra re-reads.

Branch setup (run before delegating):

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git fetch origin
git checkout -b dp-08-reservation-verification origin/dynamic-slider
```

Agent MUST, in order:

1. Read the four cited files end-to-end before editing.
2. Implement Fix 1. Run `../../../vendor/bin/phpunit --configuration phpunit.xml`. Commit.
3. Implement Fix 2. Run migration locally against a scratch SQLite DB to verify up/down both work. Run `phpunit`. Commit.
4. Implement Fix 3. Run `phpunit`. Commit.
5. Implement Fix 4. Run `phpunit`. Commit.
6. Update `03-API.md` + `CHANGELOG.md` entries. Commit.
7. Push:
   ```bash
   cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
   git push -u origin dp-08-reservation-verification
   ```
8. Open PR:
   ```bash
   gh pr create --base dynamic-slider --title "fix(reservation): self-exclusion, idempotency, bounds validation, strict has_capacity" --fill
   ```
9. **Enter the `/ralph-loop` review cycle defined below.** Do not mark the plan complete until the PR is merged and the branch is cleaned up.

---

## `/ralph-loop` — CodeRabbit review cycle

**Mandatory discipline:** after every push or `@coderabbitai` mention, the agent WAITS for CodeRabbit's response before taking the next review action. No racing ahead. No premature merge.

### Loop semantics

1. **After pushing to the PR branch (initial push or any subsequent fix push):**
   - Wait for CodeRabbit's review to appear. CodeRabbit typically posts within 2–10 minutes but can take longer.
   - Poll via `gh pr view <number> --json reviews,comments` at a sensible cadence (every 60–120 seconds, not faster). Do not spam.
   - Do not proceed until CodeRabbit has posted a review or comments for the latest commit SHA.

2. **Once CodeRabbit has responded:**
   - Read every new CodeRabbit comment. For each one:
     - **Relevant + correct for our codebase/design** → make the fix. Batch multiple fixes into logical commits. Push when done.
     - **Not relevant** → post a reply on the PR mentioning `@coderabbitai` with a clear, specific rejection explaining why (cite code/decisions/docs). Do not silently ignore.
   - While fixing, also scan for issues CodeRabbit missed — if you find any, fix them in the same round and note them in the commit message.

3. **After each commit+push in response to CodeRabbit:**
   - Go back to step 1 (wait for CodeRabbit's re-review). CodeRabbit re-reviews automatically on new commits.

4. **After posting `@coderabbitai` rejection comments (without code changes):**
   - Wait for CodeRabbit's reply. CodeRabbit will either accept the reasoning or counter. Do not merge or proceed while a `@coderabbitai` mention is unanswered.

5. **Terminating condition — all of these MUST be true before merge:**
   - CodeRabbit's most recent review has no unresolved actionable comments (either addressed or rejection accepted by CodeRabbit).
   - **All PR checks are passing and NOT pending.** Poll `gh pr checks <number>`. If any check is `PENDING` or `IN_PROGRESS`, wait. If any is `FAILED`, fix it and go back to step 1.
   - No unreplied `@coderabbitai` mentions from the agent.
   - You (the agent) are satisfied no further improvements are needed.

6. **Merge:**
   ```bash
   gh pr merge <number> --squash --delete-branch --subject "fix(reservation): self-exclusion, idempotency, bounds validation, strict has_capacity (#N)"
   ```

7. **Post-merge cleanup:**
   ```bash
   cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
   git checkout dynamic-slider
   git fetch origin --prune
   git pull origin dynamic-slider
   ```

8. **Update PROGRESS.md** with the final squash SHA and mark dp-08 shipped.

### Hard rules for the loop

- **WAIT AFTER EVERY PUSH.** Do not poll CodeRabbit faster than once every 60 seconds. Do not proceed to any "next step" while CodeRabbit's review for the current SHA is pending.
- **WAIT AFTER EVERY `@coderabbitai` MENTION.** CodeRabbit replies to mentions; treat those replies as gating.
- **ALL PR CHECKS MUST BE GREEN.** "Pending" is not green. "Failed" is not green. If CI is still running, wait. Do not merge on a yellow/pending CI.
- **NO SILENT REJECTIONS.** Every CodeRabbit suggestion you reject must have a PR comment explaining why, with `@coderabbitai` tagged.
- **NO SCOPE CREEP.** If CodeRabbit suggests something genuinely out of scope for dp-08, reject with a clear rationale and optionally note it as a candidate for a future plan (dp-14+).

### Failure modes and recovery

| Failure | Recovery |
|---|---|
| CodeRabbit never responds after 30 minutes | Post a gentle `@coderabbitai` ping on the PR asking for review. Continue waiting. |
| CI fails | Read the failure, fix, commit, push, re-enter the wait-for-CodeRabbit loop. |
| CodeRabbit re-opens an issue you thought was resolved | Reassess honestly. Either implement the real fix or post a more thorough `@coderabbitai` rejection. |
| Merge conflicts with `dynamic-slider` | Rebase onto latest `origin/dynamic-slider`, run tests, force-push (`git push --force-with-lease`), wait for CodeRabbit re-review. |

---

## Out of scope

- Authorization reform for `ReservationController::get|cancel|extend` (broken `is_admin` checks) — that's dp-11.
- Reservation funnel observability / metrics — dp-12.
- Slider UX + a11y (includes the Livewire `.live` spam fix) — dp-10.
- Any pricing-layer changes — that's dp-core-01 + dp-09.
- Audit-log reliability / `safeAudit` swallowed failures — dp-13.
- `released` state or `base_plus_addon` retirement — shipped in dp-07.
