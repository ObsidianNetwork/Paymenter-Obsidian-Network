# dp-02 / dp-03 reviewer follow-ups

Independent reviewer flagged three non-blocker issues after the round-3 parse-error hotfix landed. User approved all three. This plan amends both PR heads.

## Starting state

- PR#1 `dp-02-http-resilience` HEAD `4dd9e18` (local == remote)
- PR#2 `dp-03-audit-log-coverage` HEAD `40250f4` (local == remote, post-hotfix)

Both pass `phpunit` (7/7 and 42/42 respectively). Both clean `git status` except untracked `AGENTS.md`.

## Identity for amends (use `-c` flags, do NOT modify global config)

```
-c user.name=Jordanmuss99
-c user.email=164892154+Jordanmuss99@users.noreply.github.com
```

## Scope

Only three files change, across two branches. Do not touch any other file.

| # | Branch | File | What |
|---|---|---|---|
| 1 | `dp-03-audit-log-coverage` | `Models/Observers/AlertConfigObserver.php` | Redact `webhook_url` in `created()` payload |
| 2 | `dp-02-http-resilience` | `Services/ResourceCalculationService.php` | Add `is_array()` guards in `testConnection()` |
| 3 | `dp-03-audit-log-coverage` | `tests/Unit/CartItemDeletedListenerTest.php` | Replace 4× `Assert::assertTrue(true)` with `$this->addToAssertionCount(1)` |

Note: reviewer labelled fix 3 as "`ReservationServiceTest.php:410-413, 432-435`" but the actual `assertTrue(true)` leftovers live in `CartItemDeletedListenerTest.php` at lines 31, 68, 95, 125 — confirmed by `grep -n 'assertTrue(true)' tests/`. `ReservationServiceTest.php` already uses `addToAssertionCount(1)` throughout.

---

## Fix 1 — Redact webhook_url in AlertConfigObserver::created

**File**: `Models/Observers/AlertConfigObserver.php`
**Branch**: `dp-03-audit-log-coverage`

**Current `created()` method** (as committed on `40250f4`):

```php
public function created(AlertConfig $config): void
{
    try {
        $attrs = $config->getAttributes();
        unset($attrs['id'], $attrs['created_at'], $attrs['updated_at']);
        $this->audit->log('created', 'alert_config', $config->id, $attrs);
    } catch (\Throwable $e) {
        report($e);
    }
}
```

**Required state**:

```php
public function created(AlertConfig $config): void
{
    try {
        $attrs = $config->getAttributes();
        unset($attrs['id'], $attrs['created_at'], $attrs['updated_at']);
        if (array_key_exists('webhook_url', $attrs) && $attrs['webhook_url'] !== null && $attrs['webhook_url'] !== '') {
            $attrs['webhook_url'] = '[REDACTED]';
        }
        $this->audit->log('created', 'alert_config', $config->id, $attrs);
    } catch (\Throwable $e) {
        report($e);
    }
}
```

Rationale: preserves "webhook was/was not configured" audit signal (null/empty stays as-is, so the auditor can see a webhook wasn't set) while masking any embedded secret. Symmetric handling for `updated()` is deliberately NOT applied here because `updated()` already uses `getChanges()` which surfaces only the specific columns that changed — if an operator DOES change `webhook_url`, the audit row will still contain the new value. A follow-up plan can address `updated()` symmetry if you decide the same redaction should apply there; this plan is scoped to what the user approved.

### Test update required

`tests/Unit/AlertConfigObserverTest.php` has an exact-payload matcher for the `created` case. Read that file first. If the test constructs an `AlertConfig` with a `webhook_url`, the matcher must expect `[REDACTED]` for that key. If the test leaves `webhook_url` null/unset, no change needed.

If the test file needs an update, adjust it in the same commit.

### Verification

- `php -l Models/Observers/AlertConfigObserver.php`
- `../../../vendor/bin/phpunit` full extension suite — no regressions

---

## Fix 2 — is_array guards in testConnection

**File**: `Services/ResourceCalculationService.php`
**Branch**: `dp-02-http-resilience`

**Current** (as committed on `4dd9e18`, within the `if ($response->successful())` block):

```php
if ($response->successful()) {
    $data = $response->json();

    return [
        'success' => true,
        'message' => 'Connection successful',
        'node_count' => count($data['data'] ?? []),
        'panel_version' => $response->header('X-Pterodactyl-Version'),
    ];
}
```

Problem: 2xx with non-array body (proxy HTML, etc.) returns "Connection successful, 0 nodes" — a misleading green tick.

**Required state**:

```php
if ($response->successful()) {
    $data = $response->json();

    if (! is_array($data) || ! is_array($data['data'] ?? null)) {
        return [
            'success' => false,
            'message' => 'Connection succeeded but response body was not a valid Pterodactyl nodes payload.',
        ];
    }

    return [
        'success' => true,
        'message' => 'Connection successful',
        'node_count' => count($data['data']),
        'panel_version' => $response->header('X-Pterodactyl-Version'),
    ];
}
```

Mirror the malformed-JSON guard pattern already used in `pterodactylGet()` (established in round 2). Message wording should be clear to an admin-UI diagnostic: the HTTP succeeded, but the body is not what a Pterodactyl panel returns.

### Test update

Check `tests/Unit/ResourceCalculationServiceTest.php` for existing `testConnection()` coverage. If a happy-path test exists that fakes a valid `{"data":[...]}` response, no change needed. If no non-array 2xx test exists, adding one is optional (not required by this plan — can be deferred).

### Verification

- `php -l Services/ResourceCalculationService.php`
- `../../../vendor/bin/phpunit` full extension suite — no regressions

---

## Fix 3 — assertTrue(true) cleanup

**File**: `tests/Unit/CartItemDeletedListenerTest.php`
**Branch**: `dp-03-audit-log-coverage`

Four instances at lines 31, 68, 95, 125. Each is the last statement of a Mockery-expectation-based test method. Mockery `close()` in `tearDown()` verifies expectations, so the `assertTrue(true)` is a no-op placeholder that satisfies PHPUnit's "at least one assertion" expectation.

**Replace each**:
```php
        Assert::assertTrue(true);
```

**With**:
```php
        $this->addToAssertionCount(1);
```

Why: `addToAssertionCount(1)` is the idiomatic PHPUnit instance-method way to register a passing assertion when verification happens elsewhere (here: Mockery tearDown). Matches the pattern already used in `ReservationServiceTest` throughout. The `use PHPUnit\Framework\Assert;` import at line 11 becomes unused — remove it.

### Verification

- `php -l tests/Unit/CartItemDeletedListenerTest.php`
- `../../../vendor/bin/phpunit --filter=CartItemDeletedListenerTest` — 4/4 pass
- `../../../vendor/bin/phpunit` full suite — no regressions

---

## Execution sequence

Work branch-by-branch so the two amends are independent.

### Branch A: `dp-02-http-resilience` (fix 2 only)

```
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git checkout dp-02-http-resilience
git pull --ff-only   # sanity: should be up to date at 4dd9e18
```

1. Edit `Services/ResourceCalculationService.php` per fix 2.
2. `php -l Services/ResourceCalculationService.php`
3. `../../../vendor/bin/phpunit` → must pass (currently 7/7 on changed tests, full suite baseline whatever it is on this branch).
4. `git add Services/ResourceCalculationService.php` — narrow only.
5. `git status -s` → confirm only that file staged, `?? AGENTS.md` untracked, no others.
6. Amend:
   ```
   git -c user.name=Jordanmuss99 -c user.email=164892154+Jordanmuss99@users.noreply.github.com \
       commit --amend --no-edit --reset-author --date="$(date -R)"
   ```
7. Verify author: `git log -1 --format='%H %an <%ae>'`
8. `git push --force-with-lease origin dp-02-http-resilience`
9. Verify remote: `git rev-parse HEAD` == `git rev-parse origin/dp-02-http-resilience`

### Branch B: `dp-03-audit-log-coverage` (fixes 1 + 3)

```
git checkout dp-03-audit-log-coverage
git pull --ff-only   # sanity: should be up to date at 40250f4
```

1. Edit `Models/Observers/AlertConfigObserver.php` per fix 1.
2. Read `tests/Unit/AlertConfigObserverTest.php`. If the `created` test's payload matcher references a non-null `webhook_url`, update the expected value to `[REDACTED]`. Otherwise leave alone.
3. Edit `tests/Unit/CartItemDeletedListenerTest.php` per fix 3 (4 replacements + remove unused `use PHPUnit\Framework\Assert;` line).
4. `php -l` on each modified file.
5. `../../../vendor/bin/phpunit` → 42/42 (or whatever the baseline is on this branch).
6. `git add` narrowly — only the modified files.
7. `git status -s` → confirm only intended files staged, `?? AGENTS.md` untracked.
8. Amend with same identity flags as above.
9. `git push --force-with-lease origin dp-03-audit-log-coverage`
10. Verify remote rev-parse sync.

## Hard constraints

- All work inside `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (nested git repo). Never commit from outer paymenter.
- Narrow `git add` always. `AGENTS.md` remains untracked.
- Amend, do not create new commits.
- `--force-with-lease`, never `--force`.
- Use `-c` flags for identity, never `git config --global`.
- No scope creep: change only the files and lines enumerated here. Do not reformat adjacent code. Do not bump version numbers. Do not touch CHANGELOG or PROGRESS logs.

## Failure modes to STOP and report (do not improvise)

- Any branch HEAD doesn't match expected SHA (`4dd9e18` / `40250f4`) on checkout: STOP.
- Any file's current state doesn't match the "Current" excerpt: STOP.
- `php -l` fails on any modified file: STOP.
- Any phpunit test regresses: STOP with failing test names.
- `git add` picks up more than intended: STOP with `git status -s`.
- `git push` rejected for any reason: STOP with verbatim rejection.
- `AlertConfigObserverTest` asserts on a specific webhook_url value but you can't tell from a read whether the test already handles redaction: STOP and report the current test expectation so it can be reconciled.

## Deliverables

Final report must include, for EACH branch:
1. New HEAD SHA
2. `php -l` output on every modified file
3. PHPUnit summary line
4. `git log -1 --format='%H %an <%ae>'` on amended tip
5. Confirmation `git rev-parse HEAD` == `git rev-parse origin/<branch>`
6. `git status -s` final output

## Acceptance criteria

- Both branches: new SHA, author `Jordanmuss99 <164892154+...>`, remote synced
- Both branches: full phpunit suite green, no regressions
- Both branches: `git status -s` shows only `?? AGENTS.md`
- All three fixes present, verifiable by diff against starting SHA
- No files modified outside the three listed above
