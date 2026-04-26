# DynamicPterodactyl — Reservation Lifecycle Fixes

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (nested git repo, branch `dynamic-slider`)
**Root repo**: `/var/www/paymenter/` (branch `dynamic-slider/1.4.7`, Paymenter fork, Laravel 12 / PHP 8.3 / Filament 4)
**Type**: Bug-fix bundle — three correctness issues in the reservation state machine plus one cron registration.

---

## Problem summary

The extension maintains a `ptero_resource_reservations` state machine: `(new) → pending → {confirmed | cancelled | expired}`.

Lifecycle trace revealed three real defects and one downgraded concern:

1. **Cart-clear vs. Invoice-paid race (HIGH)** — Paymenter core `App\Livewire\Cart::checkout()` (line ~242) clears the cart *after* DB commit but *before* `Invoice\Paid` fires. `CartItemDeletedListener` cancels the reservation during that clear; `InvoicePaidListener::confirm()` later finds no `pending` row and silently no-ops. Server still provisions (because `verifyAvailability` queries live Pterodactyl state), but the reservation row stays `cancelled` — statistics, audit trail, and service linkage all break. Confirmed via explore trace:
   - `App\Livewire\Cart.php:242` clears cart after commit
   - `App\Observers\CartItemObserver.php:55` fires `CartItem\Deleted`
   - `App\Observers\InvoiceObserver.php:48` fires `Invoice\Paid` later (on status→paid)

2. **`InvoicePaidListener` ignores `confirm()` return (MEDIUM)** — `Listeners/InvoicePaidListener.php:80` calls `confirm()` but discards the boolean return. Combined with #1, silent failure. Even without #1, this hides any future race.

3. **Cleanup cron never registered (LOW-MEDIUM)** — `DynamicPterodactyl.php:95-96` has a TODO. `ReservationService::cleanupExpired()` exists at `:266` but has no caller. Impact is bounded because `ResourceCalculationService::getPendingReservations():247` already filters `expires_at > now()` when computing availability (so capacity math is correct). Residual harm:
   - `ptero_resource_reservations` grows unbounded.
   - `ReservationService::getAll()` / `getStatistics()` show expired rows as `pending` → skewed admin dashboards and `conversion_rate`.
   - `ReservationService::confirm():105` updates `WHERE status='pending'` without `expires_at` check → technically allows a past-TTL reservation to be confirmed.

4. **[DOWNGRADED — park]** — TTL value (`reservation_ttl` setting) is cached at container construction in `ReservationService::__construct:26-31`. Admin changes don't propagate until queue restart. Not urgent.

---

## Design decisions

- **Fix #1 in the extension, not core.** Modify `CartItemDeletedListener` to detect the checkout path: if a `Service` already carries the `_reservation_token` in its `properties`, the cart item was consumed by checkout → skip `cancel()`. Don't touch Paymenter core ordering.

  Why this check works: core copies `cart_item.checkout_config` → `Service::properties()` at `App\Livewire\Cart.php:184-190` **before** `Cart::clear()` at `:242`. So by the time `CartItem\Deleted` fires, the token already lives on a `Service` row. True abandonment (user removes item from cart without checkout) has no such `Service`, so `cancel()` still runs.

- **Fix #2 by checking the return value and logging a warning** with `current_status` from a re-fetched reservation. Leaves a clear TODO for admin-notify to pair with the existing `:73-76` TODO.

- **Fix #3 via `Illuminate\Support\Facades\Schedule`** directly in `boot()`. Laravel 11+ auto-registers scheduled closures declared this way. `->withoutOverlapping()` prevents tail-latency pile-ups if a cleanup run is slow. `->name('...')` lets ops list/skip/muzzle it via `php artisan schedule:list`.

- **Add `expires_at > now()` filter to `confirm()`** so an expired reservation can't be confirmed even if the cron is slow or disabled. Pairs with #2's warning logging — the listener will now observe and log the expiry case.

- **Leave `getAll()` / `getStatistics()` queries untouched.** With the per-minute cron (fix #3), staleness window is ≤60s. Adding OR-expires_at logic to multiple query paths adds complexity for marginal benefit. Revisit if dashboards still look wrong after the cron is running.

- **Leave `CartItemCreatedListener` and `ReservationService::cancel()` as-is.** The gate `status = pending` in `cancel()` is correct semantics (cancelling a confirmed service-linked reservation would be wrong).

---

## Exact changes

### Change 1 — `Listeners/CartItemDeletedListener.php` (rewrite)

Rewrite the file. Add `App\Models\Service` import. Inline a `Service::whereHas('properties', ...)` existence check before `cancel()`. If a Service already carries the token, log `debug` and return without cancelling.

Full new content:

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Listeners;

use App\Events\CartItem\Deleted;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class CartItemDeletedListener
{
    public function handle(Deleted $event): void
    {
        $cartItem = $event->cartItem;

        // Reservation token is stored in checkout_config by CartItemCreatedListener
        $checkoutConfig = $cartItem->checkout_config ?? [];
        $token = $checkoutConfig['_reservation_token'] ?? null;

        if (! $token) {
            return;
        }

        // If a Service already carries this reservation token, the cart item was
        // deleted as part of a successful checkout. Paymenter core (Cart::checkout)
        // copies checkout_config into Service::properties, commits, then clears the
        // cart — all BEFORE Invoice\Paid fires. Cancelling here would race-cancel a
        // reservation that InvoicePaidListener is about to confirm. Leave it pending.
        $serviceExists = Service::whereHas('properties', function ($q) use ($token) {
            $q->where('key', '_reservation_token')->where('value', $token);
        })->exists();

        if ($serviceExists) {
            Log::debug('Skipping reservation cancel: cart item consumed by checkout', [
                'cart_item_id' => $cartItem->id,
                'reservation_token' => substr($token, 0, 8) . '...',
            ]);

            return;
        }

        try {
            $reservationService = app(ReservationService::class);
            $reservationService->cancel($token);

            Log::info('Cancelled reservation for deleted cart item', [
                'cart_item_id' => $cartItem->id,
                'reservation_token' => substr($token, 0, 8) . '...',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cancel reservation', [
                'token' => substr($token, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

### Change 2 — `Listeners/InvoicePaidListener.php` (lines 79–85)

Replace lines 79–85 (the `// Confirm the reservation` comment through the closing `]);` of the single Log::info). Keep the trailing blank at line 86.

Replacement:

```php
                // Confirm the reservation. Returns false if no pending row matched —
                // meaning the reservation was already cancelled or expired between
                // verifyAvailability() and this call (state drift).
                $confirmed = $reservationService->confirm($reservationToken, $service->id);

                if ($confirmed) {
                    Log::info('Confirmed reservation for paid service', [
                        'service_id' => $service->id,
                        'node_id' => $reservation->node_id,
                    ]);
                } else {
                    $current = $reservationService->getByToken($reservationToken);
                    Log::warning('Reservation could not be confirmed (state drift)', [
                        'service_id' => $service->id,
                        'reservation_id' => $reservation->id,
                        'current_status' => $current?->status,
                    ]);
                    // TODO: notify admin — server still provisions via Pterodactyl
                    // extension, but reservation bookkeeping/linkage is now broken.
                }
```

### Change 3 — `DynamicPterodactyl.php` (imports + boot)

1. Add `use Illuminate\Support\Facades\Schedule;` — place alphabetically between the existing `Event` (line 11) and `View` (line 12) facade imports.
2. Add `use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;` after the last Listener import (after line 16).
3. Replace the TODO comment block at lines 95–96:

   Before:
   ```php
           // TODO: Register scheduled jobs for cleanup
           // $schedule->job(new CleanupExpiredReservations)->everyMinute();
   ```

   After:
   ```php
           // Scheduled cleanup: transition expired pending reservations.
           // Keeps admin dashboards accurate and preserves the TTL guarantee on confirm().
           Schedule::call(fn () => app(ReservationService::class)->cleanupExpired())
               ->everyMinute()
               ->name('dynamic-pterodactyl:cleanup-expired-reservations')
               ->withoutOverlapping();
   ```

### Change 4 — `Services/ReservationService.php` (confirm hardening)

Insert one line after the existing `->where('status', 'pending')` in `confirm()` (line 105):

```php
            ->where('expires_at', '>', now())
```

Resulting method:

```php
public function confirm(string $token, int $serviceId): bool
{
    return DB::table('ptero_resource_reservations')
        ->where('token', $token)
        ->where('status', 'pending')
        ->where('expires_at', '>', now())
        ->update([
            'status' => 'confirmed',
            'service_id' => $serviceId,
            'updated_at' => now(),
        ]) > 0;
}
```

---

## Testing

The extension has self-contained PHPUnit (run from inside the extension dir per its AGENTS.md). Existing tests touched by this bundle:

- `tests/Unit/ReservationServiceTest.php` — has `confirm`/`cancel`/`cleanup` cases. The new `expires_at > now()` predicate in `confirm()` may break any test that confirms an expired reservation. Audit and adjust.

New tests required:

1. **`tests/Unit/CartItemDeletedListenerTest.php`** (or add to an existing listener test file):
   - **skip path**: cart item with token + a `Service` row with matching property → `cancel()` is NOT called; log emitted at `debug` level.
   - **abandonment path**: cart item with token + no matching Service → `cancel()` IS called; reservation flips to `cancelled`.
   - **no-token path**: cart item without `_reservation_token` in `checkout_config` → early return, no queries.
   - **exception path**: `cancel()` throws → error logged, no re-throw.

2. **`tests/Unit/InvoicePaidListenerTest.php`** (new or extend):
   - **success path**: pending reservation + paid invoice → `confirm` returns true, `Log::info`.
   - **state-drift path**: reservation pre-cancelled → `confirm` returns false, `Log::warning` with `current_status = 'cancelled'`.
   - **state-drift path**: reservation expired → `confirm` returns false, `Log::warning` with `current_status = 'expired'`.
   - **missing reservation path**: already covered (unchanged).

3. **`tests/Unit/ReservationServiceTest.php`** (extend):
   - **expired-cannot-confirm**: create pending reservation with `expires_at < now()`, call `confirm()`, assert returns `false` and row status unchanged.

Run commands (from `extensions/Others/DynamicPterodactyl/`):

```bash
vendor/bin/phpunit --testdox
vendor/bin/phpunit tests/Unit/CartItemDeletedListenerTest.php
```

Also run root-level checks on the one file outside the extension boundary (there isn't one — all 4 edits are inside the extension dir):

```bash
# In root /var/www/paymenter
vendor/bin/pint --test extensions/Others/DynamicPterodactyl/
vendor/bin/phpstan analyse extensions/Others/DynamicPterodactyl/ --level=5
```

---

## Risks and mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| `Service::whereHas('properties', ...)` adds a query per cart-item deletion | Certain | Typical cart has 1–3 items; one indexed lookup per delete. `properties` table is indexed on `(model_type, model_id)`. Query is fast. |
| Existing tests assume `confirm()` works regardless of `expires_at` | Possible | Audit `ReservationServiceTest::confirm_*` cases; expected to break on an expired-row confirm test if any. Fix: set fresh `expires_at` or rewrite as expired-path assertion. |
| Laravel `Schedule::call()` in an extension boot doesn't auto-register | Low | Confirmed pattern works in Laravel 11+; Paymenter 1.4 runs Laravel 12. Verify with `php artisan schedule:list` after change. |
| Cron depends on someone actually running `schedule:run` cron | External | Pre-existing operational requirement (any Laravel scheduling needs this). Document in extension README if not already. |
| False-positive service-exists check if an unrelated Service ends up with a duplicate token | Negligible | Tokens are `Str::random(64)` — collision probability negligible. |

---

## Rollout

1. Implement all 4 changes in a single commit on the extension's `dynamic-slider` branch.
2. Commit message: `fix(reservation): prevent cart-clear race, check confirm return, register cleanup cron`
3. Manual smoke test:
   - Add product with sliders to cart → reservation appears `pending`.
   - Complete checkout via test payment → reservation appears `confirmed` with `service_id` set.
   - Add → remove from cart → reservation `cancelled`.
   - Leave pending > TTL → within 60s, cron marks it `expired`.
4. Verify scheduler registration: `php artisan schedule:list | grep dynamic-pterodactyl`.
5. Don't commit from the outer Paymenter repo — this is the nested git checkout (per `extensions/AGENTS.md`).

---

## Out of scope (defer)

- Admin-notify email when `confirm()` fails (finding #2 residual TODO) — pairs with existing `AlertService::100` mail-setup TODO.
- Admin re-verification flow at `InvoicePaidListener:73-76` — separate concern.
- Dashboard N+1 API calls (earlier audit flag) — separate concern.
- Pterodactyl API retry/timeout hardening (earlier audit flag) — separate concern.
- TTL config live-reload (finding #4) — park.
- Schema migration to add a `checkout_in_progress` state — not needed with this approach.

---

## Delegation

This plan should be handed to an implementation agent via `task(subagent_type="build" | category="quick")`. All four edits are tag-precise with exact line ranges and replacement content above. The agent should:

1. Read each target file fresh to get current LINE#ID tags.
2. Apply the four edits exactly as specified.
3. Run `vendor/bin/pint` and `vendor/bin/phpstan` from the repo root against the extension dir.
4. Run `vendor/bin/phpunit` from the extension dir.
5. Report any test adjustments needed and apply them.
6. Commit inside the nested repo (`cd extensions/Others/DynamicPterodactyl && git commit`), not from root.
