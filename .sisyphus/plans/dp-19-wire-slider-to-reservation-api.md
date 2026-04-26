# dp-19 — Wire customer-facing slider to reservation API

**Source**: Production debugging session 2026-04-27. User reported "no pending reservations ever appear" after fixing the dp-core-01 migration gap. Investigation revealed the entire reservation system is orphaned — no app code calls `POST /api/dynamic-pterodactyl/reservation`.
**Scope**: Outer Paymenter (`/var/www/paymenter/`, branch `dynamic-slider`) AND extension (`extensions/Others/DynamicPterodactyl/`, branch `dynamic-slider`). Two commits in two repos.
**Type**: Missing-feature implementation. Closes a long-standing architectural gap.
**Effort**: L (1-2 days; cross-cuts frontend + Livewire + checkout + extension service).
**Severity**: high. Without this, the reservation system, capacity alerts (dp-12/17), capacity-fanout perf (dp-18), rate limiting (dp-14), and admin reservation UI are all dead code. The "real-time capacity protection" the docs describe doesn't exist.
**Suggested branch**: `dp-19-wire-slider-reservation` (same name in both repos).

---

## Problem

`dynamic-slider.blade.php` (in `/var/www/paymenter/resources/views/components/form/`) only fetches pricing previews. It never calls `POST /api/dynamic-pterodactyl/reservation`. `Cart::checkout()` writes `service_configs` rows but never confirms or even looks up a reservation.

Evidence:
```bash
$ grep -rn 'api/dynamic-pterodactyl/reservation' /var/www/paymenter/{app,resources,themes,public/js}
# zero matches

$ grep -rn 'ReservationService\|createReservation' /var/www/paymenter/app
# zero matches
```

The extension ships a complete reservation backend — table, model, service, controller, admin UI, alerts, throttling, and 30+ tests — that is never invoked from the customer flow. The 10 historical rows in `ptero_resource_reservations` are all from December 2025 manual testing.

The advertised capacity-protection contract (per `extensions/Others/DynamicPterodactyl/03-API.md`):
> 1. Customer adjusts slider → frontend reserves capacity (token returned)
> 2. Customer adds to cart → reservation linked to cart_item_id
> 3. Customer checks out → reservation confirmed; service created with reserved capacity

Step 2 (and therefore 3) was never implemented on the frontend.

## Goal

Slider-driven products create real reservations as the customer configures them — **including guest (logged-out) visitors**, since not every customer has an account at the point of configuration. Reservations are confirmed at checkout (where Paymenter requires authentication for purchase). The capacity-protection guarantees the system documents are actually enforced. dp-12/14/17/18 stop being theoretical.

## Out of scope

- Admin UI changes — `ReservationResource` already shows pending/confirmed/cancelled holds.
- Pricing changes — pricing flow is independent.
- Multi-product carts where each product has its own slider configuration — handled naturally because each cart-item gets its own reservation token.

## Design

### Reservation lifecycle on the product page

1. Customer (auth or guest) lands on a slider-configured product page. Sliders default to their `default` value. **No reservation yet.**
2. Customer makes the FIRST adjustment to ANY slider on the page → after a 500 ms debounce, JS POSTs `/api/dynamic-pterodactyl/reservation` with all current slider values. The returned token is stored in Alpine state and persisted to `sessionStorage` (key: `dp_reservation_token_<product_id>_<plan_id>`). **At this point there is NO cart_item yet** — the API must accept the request without `cart_item_id` (see Backend changes below).
3. On any SUBSEQUENT slider change → debounce 500 ms, then POST again WITH the same `Idempotency-Key`. The API's idempotency layer returns the existing reservation if it's still pending; otherwise it creates a new one. The client always uses the latest token.
4. Customer clicks "Add to cart" → Paymenter's standard cart flow creates the `CartItem` (in a guest-cookie cart if logged out, or in the user's cart if authenticated). The Livewire add-to-cart handler reads the token from `sessionStorage` and writes it into `cart_items.checkout_config['dp_reservation_token']`.
5. (Guest only) Customer reaches the cart and proceeds to checkout. Paymenter forces login/registration here. **`UserAuthListener` automatically transfers the guest cart's `user_id`** — the cart_item with the reservation token comes along; no special handling needed in dp-19.
6. Customer reaches `/cart` checkout. `Cart::checkout()` creates the `Service` row (current line 174), then calls `ReservationService::confirm($token, $service->id, $user)`. If `confirm` fails (token expired, capacity gone), the service is rolled back and a clear error is surfaced for that cart item.

### Token persistence: where does the token live?

Three storage tiers:

1. **Alpine `x-data` state on the slider container** — ephemeral, holds the token while the customer is on the product page.
2. **Browser `sessionStorage`** — survives page navigation within the same tab. Key: `dp_reservation_token_<product_id>_<plan_id>`. This is the bridge from product-page configuration to the add-to-cart action. Works identically for auth and guest users.
3. **Cart item `checkout_config['dp_reservation_token']`** — once Add-to-cart fires, the token moves from sessionStorage to the cart_item row. From this point the token is part of Paymenter's normal cart persistence (cookie for guests, DB for authed users). UserAuthListener transfers it on login automatically.

### Idempotency key

`sha256((user_id ?? cart_ulid ?? new_uuid_in_sessionStorage) + ':' + product_id + ':' + plan_id + ':' + location_id)`. For authenticated users this is stable per (user × product × plan × location). For guests, `cart_ulid` from the `cart` cookie provides the same stability across slider movements; if the cookie isn't set yet, fall back to a UUID generated and stored in sessionStorage on first slider change. Prevents duplicate reservations from rapid-fire slider movements without leaking auth state.

### Multi-slider coordination (memory + cpu + disk on one product)

A product with three sliders has three `dynamic-slider.blade.php` instances. Each must NOT create its own reservation. The architecture:

- A new wrapper Alpine controller (`x-data="dynamicSliderGroup(...)"` on the parent product-form `<div>`) holds the reservation state for the whole product.
- Each child slider broadcasts its current value via Alpine's `$dispatch('slider-change', { resourceType, value })`.
- The wrapper listens to `slider-change`, updates its `{ memory, cpu, disk }` map, debounces, and fires the single reservation POST.
- The wrapper exposes `currentReservationToken` which child components can read but not write.

This is one new Alpine component (~150 LOC) and a small `<div x-data="dynamicSliderGroup(...)">` wrapper added to the product-show form.

### Frontend failure modes

| Scenario | UI behaviour |
|---|---|
| Reservation API returns 422 (validation) | Inline red text under the offending slider, "Insufficient capacity at this location for X MB memory". Disable "Add to cart". |
| Reservation API returns 429 (throttled — 10 req/min) | Soft retry after 6s with backoff; if still throttled, show subtle "Updating capacity hold..." indicator without blocking. |
| Reservation API returns 5xx | Log to browser console, allow checkout to proceed without reservation (Pterodactyl will catch oversold at provisioning). Show a toast "Capacity check temporarily unavailable; provisioning will verify on completion". |
| Network offline | Same as 5xx — degraded mode, allow checkout, log to console. |
| Reservation token expires (TTL 15 min) before checkout | On checkout, `confirm($token)` returns null/false → re-attempt to create a fresh reservation; if THAT fails for capacity, block checkout with a clear message. |

### Server-side: `Cart::checkout()` confirmation hook

`app/Livewire/Cart.php` `checkout()` method gets a new branch BEFORE the existing service-creation block:

```php
// Pseudocode — verified against ReservationService::confirm($token, int $serviceId, ?User $actor): bool
foreach ($order->services as $service) {
    $cartItem = $service->cartItem; // backref or matched by index in the loop
    $token = $cartItem->checkout_config['dp_reservation_token'] ?? null;
    if (! $token) {
        continue; // not a slider product, or reservation never created (degraded path)
    }
    if (! class_exists(\Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService::class)) {
        continue; // extension uninstalled — skip enforcement
    }
    $reservationService = app(\Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService::class);
    $confirmed = $reservationService->confirm($token, $service->id, \Auth::user());
    if (! $confirmed) {
        // Reservation expired or was cancelled. Roll back service and surface error.
        // Note: the for-loop already created Service rows; we delete this one and continue.
        $service->delete();
        $this->addError("checkout.{$cartItem->id}", 'Capacity hold expired during checkout. Please refresh and reconfigure.');
        return; // halt checkout — caller decides whether to refund/retry
    }
}
```

Service-class binding stays loose: `Cart.php` does NOT require the extension. The `class_exists()` guard above keeps core decoupled — if someone disables the extension, checkout still works (without reservation enforcement).

### Where the token lives on the cart item

**Verified**: `cart_items` table has columns `[id, cart_id, product_id, plan_id, config_options, checkout_config, quantity, timestamps]`. There is **no `properties` column**. We reuse the existing `checkout_config` JSON column and store the token as `checkout_config.dp_reservation_token`. **No migration needed.**

Verify that `checkout_config` isn't already used for something that would clash (read `app/Models/CartItem.php` and grep for `checkout_config` callsites before writing).

### Backend changes (extension) — required for guests AND product-page configuration

Two changes to the extension are unavoidable to support the actual customer flow (configure-before-cart) and guest support:

**B1. Route middleware: drop `auth`, allow guests.** Change `extensions/Others/DynamicPterodactyl/routes/api.php`:
```php
// Before:
Route::prefix('api/dynamic-pterodactyl')->middleware(['web', 'auth', 'throttle:10,1'])->group(...);
// After: match the cart route's posture (Paymenter cart already supports guests via cookie)
Route::prefix('api/dynamic-pterodactyl')->middleware(['web', 'checkout', 'throttle:10,1'])->group(...);
```
Verify what `'checkout'` middleware does first (likely `App\Http\Middleware\CheckoutMiddleware` — it bootstraps `App\Classes\Cart` from cookie). The throttle key falls back to IP when no auth user, which is the right defense against guest spam.

**B2. `StoreReservationRequest` — make `cart_item_id` optional.** Customers configure on the product page **before** the cart_item exists. The current request blocks at `authorize()` line 14 (`if (! $cartItemId) return false;`) and at validation rules line 46 (`'cart_item_id' => 'required|...'`). Change both:
```php
// authorize(): if no cart_item_id, allow the request (the cart-item link is established later when Add-to-cart fires).
// If cart_item_id IS provided, keep the existing ownership check (works for guests via null === null).
public function authorize(): bool
{
    $cartItemId = $this->integer('cart_item_id');
    if (! $cartItemId) {
        return true; // pre-cart reservation; no ownership to check yet
    }
    $cartItem = CartItem::query()->with('cart')->find($cartItemId);
    if (! $cartItem || ! $cartItem->cart) {
        return false;
    }
    return $cartItem->cart->user_id === $this->user()?->id;
}

// rules(): cart_item_id becomes nullable
'cart_item_id' => 'nullable|integer|exists:cart_items,id',
```

**B3. `ReservationController::create` — propagate guest user_id as null.** The controller already does `$request->user()?->id`. Verify this passes through correctly to `ReservationService::create()` and that the service stores `user_id = null` for guest reservations.

**B4. Cart-item linkage at Add-to-cart time.** Once a cart_item exists, the reservation should be linked to it (so admin views can show which cart_item a hold belongs to). Two options:
- **Option A (preferred)**: Add a new endpoint `POST /api/dynamic-pterodactyl/reservation/{token}/link` that sets `cart_item_id` on an existing reservation (requires the caller own the cart_item).
- **Option B**: Skip the backref — the cart_item already references the token via `checkout_config`, which is enough for `confirm()`. Admin Reservation views won't show the cart_item link, but capacity accounting still works.

Pick Option B for dp-19 unless the implementer finds a strong reason for Option A. One-directional link is simpler and the audit trail still works via `service_id` after confirmation.

## Edits


### Outer Paymenter (`/var/www/paymenter/`, dynamic-slider)

- `resources/views/components/form/dynamic-slider.blade.php` — emit `$dispatch('slider-change', ...)` on value change. Remove no other behaviour.
- `themes/default/views/components/form/configoption.blade.php` (or the parent product form) — wrap the slider section in `<div x-data="dynamicSliderGroup(...)">` when the product has any `dynamic_slider` config options.
- New file: `resources/js/dynamic-slider-group.js` — Alpine component for reservation coordination. Bundle into existing JS pipeline.
- `app/Livewire/Cart.php` — add the `confirm($token)` branch in `checkout()`.
- `app/Livewire/Products/Show.php` (or whichever Livewire component renders the product configuration page) — add `addedToCart` lifecycle hook to copy `sessionStorage` token to `cart_items.checkout_config['dp_reservation_token']`.
- **No migration needed** for the token storage — reuse the existing `cart_items.checkout_config` JSON column.
- Tests: feature test `tests/Feature/DynamicSliderReservationFlowTest.php` covering happy path + expiry recovery + extension-disabled fallback.

### Extension (`extensions/Others/DynamicPterodactyl/`, dynamic-slider)

- `routes/api.php` — swap `'auth'` for `'checkout'` middleware on the reservation route group (B1).
- `Http/Requests/StoreReservationRequest.php` — make `cart_item_id` optional in both `authorize()` and `rules()` (B2).
- `Http/Controllers/Api/ReservationController.php` — verify guest `user_id = null` flows through (B3).
- `Services/ReservationService.php` — **verified to exist** with signature `confirm(string $token, int $serviceId, ?User $actor): bool`. No changes needed; verify the policy gate `confirm` ability is granted to the cart-checkout actor.
- `03-API.md` — update the "Reservation Lifecycle" section to reflect the actual wiring (no longer aspirational); document guest support and the relaxed `cart_item_id` requirement.
- `06-FRONTEND.md` — document the new `dynamicSliderGroup` Alpine component.
- New tests: extend `tests/Feature/ReservationApiTest.php` with guest-creation scenarios + a "called from frontend" smoke test.

## Tests

### Unit (extension)

- `ReservationService::confirm()` happy path — pending → confirmed.
- `ReservationService::confirm()` token expired — returns false.
- `ReservationService::confirm()` token cancelled — returns false.

### Feature (outer Paymenter)

- `test_slider_change_creates_reservation` — POST to product page Livewire endpoint, simulate slider change, assert HTTP fake captures POST to `/api/dynamic-pterodactyl/reservation`.
- `test_add_to_cart_persists_reservation_token` — verify `cart_items.checkout_config['dp_reservation_token']` is set.
- `test_checkout_confirms_reservation` — full end-to-end: configure → cart → checkout → assert reservation row status moves pending → confirmed AND service created.
- `test_checkout_re_reserves_on_expiry` — set reservation to expired before checkout, assert checkout creates a fresh reservation and proceeds.
- `test_checkout_blocks_on_no_capacity` — mock reservation API returning 422, assert checkout halts with clear error.
- `test_extension_disabled_checkout_still_works` — mock `class_exists()` returning false, assert checkout proceeds without reservation logic.
- `test_guest_can_create_reservation_without_cart_item_id` — simulate guest POST to reservation API without auth, with no cart_item_id; assert 200, token returned, AND `user_id IS NULL` on the resulting `ptero_resource_reservations` row (verifies B3).
- `test_guest_reservation_token_persists_through_login` — guest creates reservation, adds to cart (token in cookie cart's cart_item.checkout_config), logs in, checkout confirms reservation — end-to-end with the auto-transfer via UserAuthListener.
- `test_guest_reservation_throttled_by_ip` — simulate 11 guest reservation POSTs from same IP, assert 11th returns 429.

### Browser (Playwright, optional but recommended)

- Load product page → move memory slider → assert network panel shows POST to reservation endpoint within 1s of stopping movement.
- Verify the inline capacity-error message appears when reservation API returns 422.

## Acceptance

```bash
cd /var/www/paymenter
php artisan migrate
php vendor/bin/phpunit tests/Feature/DynamicSliderReservationFlowTest.php  # green
cd extensions/Others/DynamicPterodactyl
../../../vendor/bin/phpunit tests/Feature/ReservationApiTest.php           # green
# Manual: load a slider product, move sliders, watch network tab for reservation POST.
# Manual: complete checkout, verify ptero_resource_reservations row is 'confirmed'.
```

## Commit

Two commits in two repos.

**Outer Paymenter:**
```
feat(checkout): wire dynamic_slider products to capacity-reservation API (dp-19)
```

**Extension:**
```
docs+test(reservation): align 03-API.md with shipped frontend wiring (dp-19)
```

## Delegation

`task(category="deep", load_skills=["code-review"], run_in_background=true, ...)`. Cross-cuts frontend Alpine + Livewire + checkout + extension service + tests. Deep category fits — needs holistic understanding of the cart pipeline.

The subagent must:

**Pre-flight reads (do these BEFORE designing or writing code):**
1. Read `app/Livewire/Cart.php` and `app/Models/CartItem.php` end-to-end — token-persistence step depends on understanding the existing cart pipeline.
2. Read `app/Http/Middleware/CheckoutMiddleware.php` (or whatever `'checkout'` resolves to in `bootstrap/app.php`) — verify it allows guests via cookie cart and bootstraps `App\Classes\Cart`.
3. `grep -rn 'checkout_config' app/ — confirm the JSON column key namespace is free for `dp_reservation_token`.

**Cross-repo discipline (CRITICAL — from `/var/www/paymenter/CLAUDE.md`):**
4. Outer Paymenter changes commit from `/var/www/paymenter/` (branch `dynamic-slider`).
5. Extension changes commit from INSIDE `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (also branch `dynamic-slider`). Do NOT stage extension files from the outer working tree — the outer repo's CodeRabbit rule will FAIL the PR.
6. Open separate PRs in each repo; cross-link them in the PR bodies.

**Already verified (do NOT re-verify, just use):**
- `cart_items.checkout_config` JSON column exists — use it for token storage; no migration needed.
- `ReservationService::confirm(string $token, int $serviceId, ?User $actor): bool` exists — create service first, then confirm; rollback on false return.

## Risks

- **Location selection is unsolved upstream** — the reservation API requires `location_id`, but the slider Blade has no location picker. The implementation MUST first locate where in the customer flow the location is chosen (likely on the product page above the sliders, or in a separate Livewire component). If the product is single-location, location_id can be hard-coded from product metadata; if multi-location, the dynamicSliderGroup wrapper must read the selected location and re-fire the reservation when location changes. **Treat this as the first thing the subagent investigates.**
- **Livewire ↔ Alpine sync subtlety** — `$wire.entangle().live` already keeps slider value in sync, but the token write needs `$wire.set('reservationToken', ...)` then a Livewire roundtrip. Test this explicitly.
- **Frontend bundling** — new `dynamic-slider-group.js` needs to be added to the existing JS pipeline (likely Vite). Verify the build picks it up.
- **Extension-disabled path** — if someone uninstalls the extension, `class_exists()` check must be in place, otherwise checkout breaks for everyone.
- **Race condition on rapid slider changes** — debounce 500 ms + idempotency key handles most cases, but stress-test with rapid keyboard arrow-key presses.
- **TTL too short for slow checkout flows** — 15 min default may be too tight for users who configure → switch tabs → return. Consider documenting and/or increasing default to 30 min as part of this work, OR adding a TTL-extend call when the customer reaches the cart.
- **`checkout_config` collision** — the existing JSON column may already be used for other checkout state. Implementation must `grep` for `checkout_config` callsites in `app/` before deciding the namespace key.
- **Guest abuse via reservation spam** — dropping `auth` means anyone can POST to the reservation endpoint. Mitigations in place: `throttle:10,1` falls back to IP-based key when no auth user; reservation TTL is 15 min so orphan holds free up quickly. Implementer should verify the throttle key behaviour in Laravel's `RateLimiter` for unauthenticated requests.
- **Guest cart cookie tampering** — a malicious guest could spoof another guest's cart_ulid to steal their reservation. Acceptable risk: the cart cookie is HTTP-only and signed by Laravel; this is the same trust model Paymenter already uses for guest carts everywhere else.
- **Login-time cart merge edge case** — if a guest has a cart with reservation A, and the user they log into ALSO has a stored cart with reservation B (different product), `UserAuthListener` currently picks one cart over the other. Verify behaviour: the orphaned reservation will simply expire after 15 min; no data corruption, but worth a manual smoke test.

## Status

- [ ] Plan written (you are here)
- [ ] Delegated to subagent
- [ ] Frontend `dynamicSliderGroup` Alpine component implemented
- [ ] `dynamic-slider.blade.php` emits `slider-change` events
- [ ] `cart_items.checkout_config['dp_reservation_token']` write path implemented
- [ ] `Cart::checkout()` confirms reservation (with extension-disabled fallback)
- [ ] `ReservationService::confirm($token, $serviceId, $actor)` policy gate verified for cart-checkout actor
- [ ] Outer Paymenter feature tests green
- [ ] Extension feature tests green
- [ ] Documentation updated (`03-API.md`, `06-FRONTEND.md`)
- [ ] PR opened in outer Paymenter
- [ ] PR opened in extension
- [ ] CR review cycle complete (both PRs)
- [ ] Both PRs merged
- [ ] PROGRESS.md entries added in both repos
- [ ] Manual smoke: configure → cart → checkout → verify confirmed reservation row in `ptero_resource_reservations`
- [ ] Backend B1: route middleware swapped to `'checkout'`
- [ ] Backend B2: `cart_item_id` made optional in `StoreReservationRequest`
- [ ] Backend B3: guest `user_id = null` flow verified through controller → service
- [ ] Guest-specific tests added and green (3 new test cases)
- [ ] Manual smoke: guest configure → add to cart → register/login → checkout → verify confirmed reservation row tied to new user
