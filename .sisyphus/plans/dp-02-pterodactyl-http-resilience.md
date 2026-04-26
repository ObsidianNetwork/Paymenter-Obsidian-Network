# DynamicPterodactyl — Pterodactyl HTTP Resilience

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/Services/ResourceCalculationService.php`
**Type**: Resilience hardening. Every Pterodactyl API call gets timeout, retry, and consistent error surfaces.

---

## Problem

`ResourceCalculationService` is the extension's only outbound HTTP client to Pterodactyl. Current state (audit finding #3):

- `testConnection()` — 10s timeout, no retry.
- `fetchNodesInLocation()`, `fetchServersOnNode()`, `getLocations()`, `getNodeLocation()` — no timeout, no retry, no rate-limit handling.
- On transient Pterodactyl slowness: customer checkout → 500 (NodeSelectionService re-throws `RuntimeException`). Admin dashboard → stalls for 30s × number-of-nodes.
- On Pterodactyl 429: silent failure, no backoff.

Paymenter sits between customer requests and Pterodactyl. Any blip becomes a Paymenter outage without this hardening.

---

## Design

### Add a private helper method
Centralise all Pterodactyl HTTP calls through one method so timeout/retry/error surfaces are defined once. Name candidates: `pterodactylGet`, `apiRequest`, or `client`. Pick one matching existing code style.

```php
private function pterodactylGet(string $path, array $query = []): array
{
    $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ])
        ->timeout(5)
        ->connectTimeout(3)
        ->retry(2, 250, function ($exception, $request) {
            // Retry on connection errors and 429 Too Many Requests.
            // Do not retry 4xx (other than 429) or 5xx with body — Pterodactyl returns meaningful errors.
            if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                return true;
            }
            return false;
        }, throw: false)
        ->get(rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/'), $query);

    if ($response->status() === 429) {
        throw new \RuntimeException('Pterodactyl rate limit exceeded. Retry in a few seconds.');
    }
    if ($response->failed()) {
        throw new \RuntimeException(sprintf(
            'Pterodactyl API error (%d): %s',
            $response->status(),
            $response->body()
        ));
    }
    return $response->json() ?? [];
}
```

Design decisions explained:

- **5s timeout** — checkout can't wait longer than this without feeling broken. 3s connectTimeout catches dead panels fast.
- **2 retries, 250ms backoff** — exponential-ish behaviour via `retry()`'s sleep arg. Only retries on connection-level failures, not HTTP error codes (those are meaningful).
- **`throw: false`** — we own the error wrapping, not Laravel's default exception.
- **Re-throw `RuntimeException`** — preserves current caller contract. `NodeSelectionService` and admin pages already expect this.
- **429 gets its own message** — admin sees "rate limit exceeded" not "HTTP 429".

### Refactor existing callers
Replace every direct `Http::` call with `$this->pterodactylGet(...)`. Call sites (from audit):
- `getLocationAvailability()` (line ~24)
- `fetchNodesInLocation()`
- `fetchServersOnNode()`
- `getLocations()`
- `getNodeLocation()`

Each currently assembles URL + headers manually. Helper collapses that to one line.

### Leave `testConnection()` using a direct `Http::` call
Intentional divergence: `testConnection()` is the admin-pressing-a-button path. Different semantics from hot-path reads. Its 10s timeout and no-retry behaviour is correct. Add a comment explaining why it doesn't go through `pterodactylGet()`.

### Caching — defer
Adding a short cache (30s) for `getLocations()` is tempting since locations rarely change. **Defer** to a separate plan:
- Design decision required: cache invalidation strategy when admin adds a location in Pterodactyl.
- Current plan is already scoped to resilience, not performance.

---

## Exact changes

### `Services/ResourceCalculationService.php`

1. Add the `pterodactylGet()` private method (near the bottom of the class, above `getPendingReservations`).
2. Replace each `Http::withHeaders([...])->get(...)` + response parsing block with a single `$this->pterodactylGet(...)` call.
3. Add import if not present: `use Illuminate\Http\Client\ConnectionException;`
4. Comment above `testConnection()`: `// Does not use pterodactylGet() — admin-initiated diagnostic needs longer timeout and different error surfaces.`

---

## Caller behaviour

No call site needs changes. The contract is preserved: methods either return their expected shape or throw `RuntimeException`. Upstream handlers (`NodeSelectionService::selectBestNode`, admin dashboards) continue to work.

Specific contract notes for reviewers:
- `NodeSelectionService::selectBestNode()` catches nothing — it relies on `getLocationAvailability()` to throw. It already does.
- `Admin/Pages/Dashboard.php` and `NodeMonitoring.php` will need a try/catch at some point to render "Pterodactyl unreachable" gracefully. Flag for a follow-up plan if not already pinned (not in scope here — this plan just stops the timeout from being 30s).

---

## Testing

### Unit tests
Extend `tests/Unit/ResourceCalculationServiceTest.php` (create if it doesn't exist; audit didn't flag one).

Use Laravel's `Http::fake()` to simulate:
1. **timeout path**: `Http::fake(fn () => Http::response(null, 200, [])->throw(new ConnectionException('timed out')))`. Call `getLocationAvailability(1)`. Assert:
   - retries fire (use `Http::assertSentCount(3)`)
   - throws `RuntimeException` with connection message after retries exhausted
2. **429 path**: `Http::fake([...] => Http::response([], 429))`. Assert `RuntimeException` with "rate limit" in message, no retry.
3. **500 path**: `Http::fake([...] => Http::response(['error' => 'panel down'], 500))`. Assert `RuntimeException` with status 500 and body.
4. **happy path**: `Http::fake([...] => Http::response(['data' => [...]], 200))`. Assert returns array, single request (no retry).

### Integration smoke
1. Point extension at a live Pterodactyl (test instance).
2. Force a 1-second network hiccup (e.g., `sudo iptables -A OUTPUT -p tcp --dport 443 -m limit --limit 1/s -j DROP` for the Pterodactyl host).
3. Trigger admin dashboard load.
4. Expected: request completes within ~6s (5s timeout + retry), page renders with error banner or degraded data — not a 30s timeout.

---

## Acceptance

- All 5 callers use `pterodactylGet()`.
- `testConnection()` unchanged but has explanatory comment.
- Unit tests pass, including the 4 new cases.
- Existing integration paths (customer checkout, admin dashboard) still work against a healthy Pterodactyl.
- Manual smoke test: Pterodactyl-offline scenario surfaces as `RuntimeException` within 6s, not 30s.

---

## Risks

| Risk | Mitigation |
|---|---|
| 5s timeout too aggressive for slow/distant Pterodactyl panels | Make timeout configurable via extension setting (`pterodactyl_timeout`, default 5). Defer if not requested. |
| Retry doubles load on already-struggling Pterodactyl | Only 1 retry on connection-level failures. HTTP errors never retry. |
| `Http::assertSentCount` count depends on Laravel version | Verify Laravel 12 semantics; adjust assertion if needed. |
| Caller test mocks break | Grep for existing `Http::fake` usage in the extension's tests; match style. |

---

## Commit

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git add -A
git commit -m "feat(http): add timeout and retry to Pterodactyl API client"
```

---

## Delegation

`task(category="deep", load_skills=[], run_in_background=true, ...)`

Branch setup (run before delegating):

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git fetch origin
git checkout -b dp-02-http-resilience origin/dynamic-slider
```

The agent should:
1. Read `Services/ResourceCalculationService.php` fully before editing.
2. Implement `pterodactylGet()` with the helper signature above, but verify existing method signatures and adjust URL-construction logic to match.
3. Refactor each caller site to use the helper.
4. Write the 4 unit tests.
5. Run `vendor/bin/phpunit` from inside the extension dir. All must pass.
6. Commit.

Publish for review:

```bash
git push -u origin dp-02-http-resilience
gh pr create --base dynamic-slider --title "feat(http): add timeout and retry to Pterodactyl API client" --fill
```

---

## Out of scope

- HTTP response caching → separate plan if needed.
- Admin dashboard graceful-degradation UI → separate plan.
- Circuit breaker pattern (open/half-open/closed) → over-engineered for current scale.
