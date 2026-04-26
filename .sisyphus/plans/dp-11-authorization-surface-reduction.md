# dp-11 — Authorization + Surface Reduction

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (the `dynamic-slider` branch in the extension repo at `https://github.com/Jordanmuss99/dynamic-pterodactyl.git`). Extension-only; no core changes.
**Type**: Security fix + dead-code removal patch series. Same shape as dp-08 (single PR on the extension fork against `dynamic-slider`).
**Delivery**: Single PR, atomic commit per concern, squash-merge.
**Backlog mapping**: Fulfils the "dp-11: authorization + surface reduction" backlog item recorded in PROGRESS.md and explicitly deferred from dp-08 ("Authorization reform for `ReservationController::get|cancel|extend` (broken `is_admin` checks) — that's dp-11", `.sisyphus/plans/dp-08-reservation-verification.md:307`).

---

## Problem

Authorization in the extension is inconsistent and partially broken. dp-08's recon found three concrete gaps that dp-11 must close, plus an undocumented assumption (`User::is_admin` boolean) that conflicts with how Paymenter actually identifies admins (`User::canAccessPanel()` checks `role_id !== null` — `app/Models/User.php:164-168`).

Verified gaps (from recon agent, file:line cited):

1. **`ReservationController::get|cancel|extend` (`Http/Controllers/Api/ReservationController.php:57-80,83-108,110-150`)** check `auth()->user()->is_admin` to decide whether to allow non-owner access. Paymenter does not maintain `is_admin` — admins are identified via the role/panel relationship. The check is effectively dead: any non-null `is_admin` truthy value works, but the column may not exist on every install. **Severity: High** — silent behavioural drift, possibly granting non-admin behaviour as "admin" depending on schema.

2. **`StoreReservationRequest::authorize()` always returns `true` (`Http/Requests/StoreReservationRequest.php:11-33`)**, and `ReservationController::create()` (`Http/Controllers/Api/ReservationController.php:20-38`) accepts a `cart_item_id` from request input without verifying the cart item belongs to the authenticated user. `CartItem` belongs to `Cart` (`app/Models/CartItem.php:30-33`), `Cart` belongs to `User` (`app/Models/Cart.php:33-36`). **Severity: High** — IDOR: user A can create a reservation against user B's cart item by guessing the ID.

3. **`ReservationService::create|confirm|cancel|extend` (`Services/ReservationService.php:52-150,171-229`)** are token/status-driven and never validate the actor. Today the controllers/listeners gate calls, but if any future caller skips that guard, the service has zero defence in depth. **Severity: Medium** — actor-aware service-layer checks would prevent regressions.

4. **No model policies exist** for `ResourceReservation` (`Models/ResourceReservation.php:10-65`), `AlertConfig` (`Models/AlertConfig.php:7-50`), or `AuditLog` (`Models/AuditLog.php:7-37`). Authorization is ad hoc via middleware and inline checks. **Severity: Medium** — enables the gaps above and makes future work harder to keep consistent.

In parallel, the recon found dead/retired/over-broad surface that should be trimmed in the same wave so reviewers see the fix and the cleanup together:

- `PricingController::validate()` is a 410-Gone stub left over from dp-09 (`Http/Controllers/Api/PricingController.php:147-156`).
- `ReservationService::getAll()` (`Services/ReservationService.php:279-305`) duplicates `queryAll()` (`Services/ReservationService.php:315-333`); no in-repo callers.
- Several internal helpers are public when they should be private (concrete list in commit 5).

The admin route group middleware (`routes/api.php:33-39` uses `EnsureUserIsAdmin`) IS correct and is exercised by `tests/Feature/AdminApiTest.php:57-65,161-168`. Admin endpoints are NOT a gap.

---

## Design

Five concerns, five commits. Each commit must keep `php artisan test --filter=DynamicPterodactyl` (or the extension's test path) green.

### Commit 1 — `ResourceReservationPolicy` + cart ownership in `StoreReservationRequest`

**Files**:
- `Policies/ResourceReservationPolicy.php` (new)
- `Providers/DynamicPterodactylServiceProvider.php` — register the policy in `AuthServiceProvider`-style boot, or wherever the extension currently boots policies (read this file end-to-end first to find the right hook)
- `Http/Requests/StoreReservationRequest.php` — replace `authorize() { return true; }` with cart ownership check
- `tests/Feature/ReservationApiTest.php` — add IDOR test (user A cannot create reservation against user B's cart item → 403)

**Change**:

`ResourceReservationPolicy` exposes `view`, `cancel`, `extend`, `viewAny` (admin), with this rubric:
- `view`: user owns the reservation OR `$user->canAccessPanel()` returns true
- `cancel`: same as `view` plus reservation status allows cancellation (status check stays in service; policy only checks identity)
- `extend`: same as `view`
- `viewAny`: `$user->canAccessPanel()` (used by admin list endpoints if any)

Use `User::canAccessPanel()` (the same accessor admin middleware uses, `app/Models/User.php:164-168`) — do NOT introduce a new admin check.

`StoreReservationRequest::authorize()`:

```php
public function authorize(): bool
{
    $cartItemId = $this->integer('cart_item_id');
    if (!$cartItemId) {
        return false;
    }

    $cartItem = \App\Models\CartItem::query()
        ->with('cart')
        ->find($cartItemId);

    if (!$cartItem || !$cartItem->cart) {
        return false;
    }

    return $cartItem->cart->user_id === $this->user()?->id;
}
```

(Adapt to whatever `Cart`/`CartItem` namespace and ownership column the fork uses — verify by reading `app/Models/Cart.php:33-36` and `app/Models/CartItem.php:30-33` first.)

**Why first**: every subsequent commit relies on the policy. No-op if you start with the controllers.

**Test additions**: 
- `test_user_cannot_create_reservation_against_anothers_cart_item` — 403 on the request validation step
- `test_user_can_create_reservation_against_own_cart_item` — 201 (or current success status)

### Commit 2 — Replace broken `is_admin` checks in `ReservationController`

**Files**:
- `Http/Controllers/Api/ReservationController.php` (methods `get`, `cancel`, `extend`)
- `tests/Feature/ReservationApiTest.php` — add admin-vs-owner-vs-stranger tests

**Change**:

Replace every `auth()->user()->is_admin` check with `$this->authorize('view', $reservation)` / `$this->authorize('cancel', $reservation)` / `$this->authorize('extend', $reservation)` calls using the policy from commit 1. Drop the inline `if (!$user || ($reservation->user_id !== $user->id && !$user->is_admin))` blocks entirely — the policy handles both ownership and admin override.

For lookup-by-token endpoints (if any of these methods accept a token instead of an ID), keep token validation in the service but layer the policy check on the resolved reservation.

**Why second**: depends on commit 1's policy. Cannot land independently.

**Test additions**:
- `test_admin_can_view_other_users_reservation` — admin (any user with `role_id !== null`) → 200
- `test_admin_can_cancel_other_users_reservation` — same
- `test_admin_can_extend_other_users_reservation` — same
- `test_stranger_cannot_view_other_users_reservation` — non-admin, non-owner → 403
- `test_stranger_cannot_cancel_other_users_reservation` — 403
- `test_stranger_cannot_extend_other_users_reservation` — 403
- `test_owner_can_view_own_reservation` — 200 (regression guard)

### Commit 3 — Defence-in-depth: actor-aware checks in `ReservationService`

**Files**:
- `Services/ReservationService.php` — add optional `User $actor` parameter to `cancel`, `extend`, `confirm` (default `null`); when provided, perform `Gate::forUser($actor)->authorize(...)` before the mutation
- `Http/Controllers/Api/ReservationController.php` — pass `$request->user()` through to the service calls
- `Listeners/*` — any listener that calls these service methods (read each listener before deciding; if the listener fires from a system event with no user context, document why null is acceptable)
- `tests/Unit/ReservationServiceTest.php` (new or existing) — add tests for actor-mismatch → throws

**Change**:

```php
public function cancel(ResourceReservation $reservation, ?User $actor = null): void
{
    if ($actor !== null) {
        Gate::forUser($actor)->authorize('cancel', $reservation);
    }
    // ... existing status check + mutation
}
```

Actor is optional so existing internal callers (queue jobs, system events) remain unaffected. Controller callers MUST pass actor. Document the convention in a docblock at the top of the methods.

**Why third**: depends on commit 1 (policy exists) and commit 2 (controllers pass actor). Standalone defence-in-depth, no behaviour change for valid existing flows.

**Test additions**:
- `test_cancel_throws_when_actor_does_not_own_reservation`
- `test_cancel_succeeds_when_actor_is_owner`
- `test_cancel_succeeds_when_actor_is_admin`
- `test_cancel_succeeds_when_actor_is_null` (system context)
- Same trio for `extend` and `confirm`

### Commit 4 — Surface reduction: delete dead code

**Files**:
- `Http/Controllers/Api/PricingController.php` — delete `validate()` method (lines 147-156) and its route registration in `routes/api.php`
- `Services/ReservationService.php` — delete `getAll()` (lines 279-305) after grepping the entire fork for callers; if any survive, port them to `queryAll()` and delete in same commit
- Update extension `03-API.md` if it documents the retired `validate` endpoint

**Why fourth**: orthogonal to auth, but lands in the same wave so reviewers see "we fixed the gaps AND removed the dust" together. Independent of commits 1-3.

**Test additions**: 
- Update or remove any test that hits `validate()` (it was already returning 410, so any test should expect 410 — change to expect 404 or remove)
- Confirm `getAll()` removal doesn't break `tests/Feature/AdminApiTest.php` (the admin endpoint uses `queryAll()`)

### Commit 5 — Reduce helper visibility

**Files**:
- `Services/ReservationService.php` — make `presentReservation()` (lines 409-431) and `getActiveByIdempotencyKey()` (lines 152-166) `private`
- `Services/ResourceCalculationService.php` — make `calculateNodeAvailability()` (lines 71-122) `private`
- `Services/ConfigOptionSetupService.php` — make `createResourceOption()` (lines 80-116) and `createLocationOption()` (lines 174-207) `private`

**Why fifth**: pure refactor, lowest risk, easy to revert if any external caller pops up. Run the full extension test suite after — any failure indicates a missed caller.

**Test additions**: none. The full test suite is the test.

---

## Deferred (out of scope for dp-11)

These were surfaced during dp-11 recon but do NOT belong in this PR:

- **Splitting `AlertService` into capacity-scanner + shortfall-notifier** — refactor, medium risk, separate concern. Defer to a new `dp-core-NN-service-decomposition.md` or fold into dp-12 (observability) if the split improves logging boundaries.
- **Splitting `ReservationService`** (mutation vs. query/reporting) — too big to bundle with auth fixes. Defer to a follow-up plan.
- **Wiring `AlertService::checkCapacityAlerts()` to the scheduler** — that's dp-12's job (capacity alerts + observability). Leave the method in place; do NOT delete (we may delete in commit 4 only if dp-12 confirms it's dead — see "Risks" below).
- **Adding `AlertConfigPolicy` and `AuditLogPolicy`** — admin-only models, currently protected by panel middleware; adding policies is hygiene but not security. Defer unless dp-11 produces test failures requiring them.
- **`safeAudit` swallow-and-report behaviour** — dp-13 territory.
- **Doc cleanup in `03-API.md` / `05-ADMIN-UI.md`** for retired-endpoint prose — defer to a docs-only PR if useful, otherwise let the next plan that touches those docs subsume it.

---

## Testing

- After each commit: `cd /var/www/paymenter && php artisan test` from the fork root, AND `cd /var/www/paymenter/extensions/Others/DynamicPterodactyl && composer test` (if the extension has its own test runner) or `php ../../../artisan test --filter=DynamicPterodactyl`.
- New tests required: see each commit's "Test additions" section.
- Final suite must include the existing `tests/Feature/AdminApiTest.php`, `tests/Feature/ReservationApiTest.php`, `tests/Feature/PricingPreviewParityTest.php` (from dp-09), and the new policy/IDOR/actor tests.
- Manual smoke (post-merge):
  1. Log in as admin (`role_id !== null`), call `GET /api/dynamic-pterodactyl/reservation/{token}` for a non-owned reservation → 200.
  2. Log in as a regular user, attempt the same → 403.
  3. Log in as user A, attempt `POST /api/dynamic-pterodactyl/reservation` with user B's `cart_item_id` → 403.
  4. Confirm `POST /api/dynamic-pterodactyl/pricing/validate` returns 404 (not 410) — endpoint deleted.

---

## Risks

| Risk | Mitigation |
|---|---|
| `User::canAccessPanel()` semantics change in a future Paymenter upstream merge | Reference the method, not the underlying column. The policy delegates; if Paymenter changes the admin signal, the policy follows automatically. |
| Existing internal callers of `getAll()` outside this repo (e.g., a private downstream fork) | Grep the entire fork before deletion. Document the removal in the PR body. If concerned, deprecate first (mark `@deprecated` and call `queryAll()` internally) — but the recon found zero in-repo callers, so this is unlikely. |
| Listeners fire reservation mutations with no user context, breaking commit 3 | The `?User $actor = null` default means null callers behave as before. Only controller callers MUST pass actor; listeners pass null and are explicitly unauthorized (system context). Document each null call site. |
| Policy registration boilerplate not present in extension | If the extension doesn't have an `AuthServiceProvider`-style policy registration hook, register inline in the existing `DynamicPterodactylServiceProvider::boot()` via `Gate::policy(ResourceReservation::class, ResourceReservationPolicy::class)`. Verify the boot order doesn't fight Paymenter's. |
| Test fixtures don't include a non-admin user with a cart item | Add a factory or fixture helper. The tests for IDOR require two distinct users with carts — confirm `tests/Feature/AdminApiTest.php` already creates these and reuse the helper if possible. |
| `AlertService::checkCapacityAlerts()` is wired by dp-12 before dp-11 lands | If dp-11 ships first and deletes `checkCapacityAlerts()`, dp-12 has to re-add. Decision: do NOT delete in commit 4; only delete `validate()` and `getAll()`. Mark `checkCapacityAlerts()` deletion as a dp-12 sub-task. |

---

## Acceptance

- Branch `dp-11-authorization-surface-reduction` on the extension fork, PR opened against `dynamic-slider`.
- All five commits land; squash-merge as one PR.
- `ResourceReservationPolicy` exists and is registered.
- `StoreReservationRequest::authorize()` enforces cart ownership.
- `ReservationController::get|cancel|extend` use policy, no `is_admin` references remain in the file.
- `ReservationService::cancel|extend|confirm` accept optional `?User $actor` and authorize when provided.
- `PricingController::validate()` and its route are gone.
- `ReservationService::getAll()` is gone (or marked deprecated and forwarded to `queryAll()` if any external caller is a concern — but recon shows none).
- 5 helper methods made `private`.
- All tests green: existing + new IDOR/policy/actor tests.
- PROGRESS.md row updated with squash SHA after merge.

---

## Commit sequence

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git fetch origin
git checkout -b dp-11-authorization-surface-reduction origin/dynamic-slider

# Commit 1
git commit -m "feat(auth): add ResourceReservationPolicy + cart ownership in StoreReservationRequest (dp-11)"

# Commit 2
git commit -m "feat(auth): replace broken is_admin checks in ReservationController with policy (dp-11)"

# Commit 3
git commit -m "feat(auth): add optional actor-aware authorization to ReservationService mutations (dp-11)"

# Commit 4
git commit -m "refactor: delete retired PricingController::validate and ReservationService::getAll (dp-11)"

# Commit 5
git commit -m "refactor: reduce visibility of internal-only service helpers (dp-11)"

git push -u origin dp-11-authorization-surface-reduction
gh pr create --base dynamic-slider --title "feat(auth): authorization fixes + surface reduction (dp-11)" --fill
```

Author for every commit: `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`. Verify with `git log -1 --format='%an <%ae>'` after each commit.

---

## Process: Out-of-scope finding handling (inherited from dp-10)

When implementing dp-11 (or replying to CodeRabbit), if you or the reviewer identifies a change that **does not fit dp-11's scope** — for example, an observability concern, a service split, or a pre-existing bug in unrelated code paths — the agent MUST:

1. **Identify the correct destination plan**:
   - Auth/policy hygiene that's NOT in the recon list above? → stays in dp-11 only if it's a regression caused by this PR; otherwise defer.
   - Observability / logging / scheduler wiring? → `.sisyphus/plans/dp-12-…md`.
   - SetupWizard atomicity / E2E? → `.sisyphus/plans/dp-13-…md`.
   - Service decomposition (split `AlertService`, `ReservationService`)? → new `.sisyphus/plans/dp-NN-service-decomposition.md` stub.
   - Core/blade architecture? → `.sisyphus/plans/dp-core-02-blade-architecture.md` (already exists, append).
2. **Append the finding to that plan's "Deferred from dp-11" section** with: description, file:line, citation (CodeRabbit thread URL or "found during dp-11 commit N"), date.
3. **Reply to the CodeRabbit thread** (if applicable): `@coderabbitai Acknowledged. Out of scope for dp-11; deferred to dp-NN. See <plan link>.` Then resolve.
4. **Do NOT silently expand the current PR's scope.**

---

## /ralph-loop (verbatim contract)

> Use `/ralph-loop` to review the PR, read CodeRabbit's latest comments and decide if they are relevant. If not, mention CodeRabbit with `@coderabbitai` explaining why you are rejecting. If you agree, make the changes alongside any other issues you find. When done, push and **wait** for CodeRabbit to review again. Loop until you and CodeRabbit are satisfied; then merge.
>
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
- Append a `dp-11 shipped` row to `PROGRESS.md` with the squash SHA from `gh pr view <n> --json mergeCommit`.
- Commit + push the PROGRESS update on the same `dynamic-slider` branch.
- Archive `.sisyphus/boulder.json` to `.sisyphus/completed/dp-11-authorization-surface-reduction.boulder.json` and remove the active file.

---

## Out of scope

- Splitting `AlertService` or `ReservationService` (defer per "Deferred" section above).
- Adding `AlertConfigPolicy` / `AuditLogPolicy` (admin-only, panel-protected; defer unless tests demand).
- Wiring or deleting `AlertService::checkCapacityAlerts()` (dp-12).
- Any core change (blade, model, route in `/var/www/paymenter/app/` or `/var/www/paymenter/themes/`).
- Pricing math, slider UX (already shipped via dp-core-01, dp-09, dp-10).
- Documentation rewrites of `03-API.md`, `05-ADMIN-UI.md` beyond removing references to deleted endpoints.

---

## Delegation

Category: `deep`. One subagent runs all five commits sequentially on one branch.

Agent MUST:

1. Read each cited file end-to-end before editing.
2. Verify the cart ownership chain (`Cart::user_id`, `CartItem::cart_id`) by reading `app/Models/Cart.php` and `app/Models/CartItem.php` from the parent Paymenter repo before writing the policy.
3. Verify `User::canAccessPanel()` exists and works as documented (`app/Models/User.php:164-168`).
4. Verify `git config user.email` is `164892154+Jordanmuss99@users.noreply.github.com` before the first commit.
5. Implement Commit 1, run tests, commit.
6. Implement Commit 2, run tests, commit.
7. Implement Commit 3, run tests, commit.
8. Implement Commit 4, run tests, commit (grep the entire fork for callers of `getAll()` and `validate()` before deletion).
9. Implement Commit 5, run tests, commit.
10. Push: `git push -u origin dp-11-authorization-surface-reduction`.
11. Open PR against `dynamic-slider`.
12. Run the `/ralph-loop` block above until merged.
13. Apply the **out-of-scope handling process** to every CodeRabbit thread that proposes work outside the five commits above.
