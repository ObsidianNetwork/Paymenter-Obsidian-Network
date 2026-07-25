# dp-03 Reviewer Round-3 Follow-up

**Branches affected**: `dp-03-audit-log-coverage` (code + tests), PR#2 thread (CodeRabbit reply), `dp-04-shortfall-notifications.md` (deferred note)
**Starting HEAD**: `dp-03-audit-log-coverage` = `83d13c813e8ded4948a8793c70e9881030ea6a51`, remote matches
**Target**: one amended commit on `dp-03-audit-log-coverage`, one CodeRabbit comment reply, one appended note in `dp-04-shortfall-notifications.md`

## Scope

Third round of reviewer findings post-hotfix. User decisions:
- Medium (webhook_url leak on `updated()`): fix
- Item 2 (ConfigOptionSetupService indentation): fix
- Item 3 (DI inconsistency in ConfigOptionSetupService): defer to `dp-04-shortfall-notifications.md` as cleanup sidebar
- Item 4 (confirm/extend double-query): dismiss, post rationale reply to `@coderabbitai` on PR#2
- Item 5 (sparse updated/deleted payloads): expand both (rationale: PR's stated goal is audit coverage; forensic reconstruction incomplete without old/full snapshots)
- Item 1 (PR#1 description mismatch): dismissed, no action

## Constraints (inherited from prior rounds)

- Nested repo: `cd /var/www/paymenter/extensions/Others/DynamicPterodactyl/` before any git command; never commit from outer Paymenter repo.
- Git identity via flags only: `-c user.name=Jordanmuss99 -c user.email=164892154+Jordanmuss99@users.noreply.github.com`.
- Amend `83d13c8` (`--amend --no-edit --reset-author --date="$(date -R)"`). Do not create a new commit.
- Narrow `git add <file>` only — `AGENTS.md` must remain untracked (`?? AGENTS.md` in final `git status -s`).
- Push with `--force-with-lease origin dp-03-audit-log-coverage` only.
- If `git rev-parse HEAD` != `83d13c813e8ded4948a8793c70e9881030ea6a51` at start, abort.

## 1. `Models/Observers/AlertConfigObserver.php` — expand + redact

Current state (verified):
- `updated()` logs `['changes' => $config->getChanges()]` — leaks `webhook_url` plaintext when admin rotates it; also drops old values so diff is one-sided.
- `deleted()` logs only `['location_id' => $config->location_id]` — loses thresholds, notification_emails, webhook config, etc.
- `created()` already redacts `webhook_url` via array-key check (shipped in round-2).

`AuditLogService::log()` signature (verified `Services/AuditLogService.php:15-23`):

```php
public function log(
    string $action,
    string $entityType,
    int $entityId,
    ?array $newValues = null,
    ?array $oldValues = null,
    ?string $description = null,
    ?string $entityName = null
): int
```

Old values are a first-class positional param (5th). Pass them directly rather than nesting under `['changes' => ..., 'original' => ...]`.

### Target code

```php
public function updated(AlertConfig $config): void
{
    try {
        $changes = $config->getChanges();
        $original = array_intersect_key(
            $config->getOriginal(),
            array_flip(array_keys($changes))
        );

        $this->redactWebhook($changes);
        $this->redactWebhook($original);

        $this->audit->log(
            'updated',
            'alert_config',
            $config->id,
            $changes,
            $original
        );
    } catch (\Throwable $e) {
        report($e);
    }
}

public function deleted(AlertConfig $config): void
{
    try {
        $attrs = $config->getAttributes();
        unset($attrs['id'], $attrs['created_at'], $attrs['updated_at']);
        $this->redactWebhook($attrs);

        $this->audit->log('deleted', 'alert_config', $config->id, $attrs);
    } catch (\Throwable $e) {
        report($e);
    }
}

private function redactWebhook(array &$attrs): void
{
    if (array_key_exists('webhook_url', $attrs)
        && $attrs['webhook_url'] !== null
        && $attrs['webhook_url'] !== '') {
        $attrs['webhook_url'] = '[REDACTED]';
    }
}
```

Also refactor `created()` to use the shared `redactWebhook()` helper instead of inline logic (internal cleanup, no behavior change).

### Semantics

- Empty/null webhook stays as-is (preserves "was it set or not" signal).
- Old-value redaction covers webhook rotation scenario: admin changes URL → both old and new are `[REDACTED]` rather than one side leaking.
- `deleted()` snapshot restores forensic audit value without reopening the leak.

## 2. `Services/ConfigOptionSetupService.php` — re-indent try/catch

Current (cosmetic issue — hotfix landed the brace correctly but did not re-indent the body one level deeper under the `if`):

```php
        if (! empty($created)) {
            /** @var ... $audit */
            $audit = app(\Paymenter\...\AuditLogService::class);
        try {                                  // <-- col 8, should be col 12
            $audit->log('setup_run', 'product_config', $productId, [
                'sliders_configured' => array_keys($created),
                'count' => count($created),
            ]);
        } catch (\Throwable $e) {              // <-- col 8, should be col 12
            report($e);
            }                                  // <-- col 12, should be col 16
        }
```

### Target (re-indent by 4 spaces)

```php
        if (! empty($created)) {
            /** @var \Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService $audit */
            $audit = app(\Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService::class);
            try {
                $audit->log('setup_run', 'product_config', $productId, [
                    'sliders_configured' => array_keys($created),
                    'count' => count($created),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
```

Purely whitespace. `php -l` must still pass. Do not change the `app(...)` pattern here — DI refactor is deferred (see section 5).

## 3. `tests/Unit/AlertConfigObserverTest.php` — update expectations

Current tests (read from working tree):

- `test_created_logs_audit_entry` — no webhook, positive control. Leave unchanged.
- `test_updated_logs_changes` — currently expects `['changes' => [...]]` 4-arg call. Must be updated to the 5-arg positional form with `$oldValues`.
- `test_deleted_logs_audit_entry` — currently expects `['location_id' => 1]`. Must be updated to full snapshot.

### Target test updates

```php
public function test_updated_logs_changes(): void
{
    $config = Mockery::mock(AlertConfig::class)->makePartial();
    $config->id = 10;
    $config->shouldReceive('getChanges')->andReturn(['is_active' => false]);
    $config->shouldReceive('getOriginal')->andReturn([
        'is_active' => true,
        'memory_warning_threshold' => 80,
        'location_id' => 1,
    ]);

    $this->mockAudit->shouldReceive('log')
        ->once()
        ->with(
            'updated',
            'alert_config',
            10,
            ['is_active' => false],
            ['is_active' => true]  // original restricted to changed keys only
        )
        ->andReturn(1);

    $this->observer->updated($config);
    $this->addToAssertionCount(1);
}

public function test_deleted_logs_audit_entry(): void
{
    $config = new AlertConfig([
        'location_id' => 1,
        'location_name' => 'US East',
        'is_active' => true,
        'memory_warning_threshold' => 80,
    ]);
    $config->id = 10;

    $this->mockAudit->shouldReceive('log')
        ->once()
        ->with('deleted', 'alert_config', 10, Mockery::on(function ($attrs) {
            return $attrs['location_id'] === 1
                && $attrs['location_name'] === 'US East'
                && $attrs['is_active'] === true
                && $attrs['memory_warning_threshold'] === 80
                && ! array_key_exists('id', $attrs)
                && ! array_key_exists('created_at', $attrs)
                && ! array_key_exists('updated_at', $attrs);
        }))
        ->andReturn(1);

    $this->observer->deleted($config);
    $this->addToAssertionCount(1);
}
```

### New tests to add (prove redaction symmetry)

```php
public function test_updated_redacts_webhook_url_on_both_sides(): void
{
    $config = Mockery::mock(AlertConfig::class)->makePartial();
    $config->id = 10;
    $config->shouldReceive('getChanges')->andReturn([
        'webhook_url' => 'https://hooks.slack.com/services/NEW_SECRET',
    ]);
    $config->shouldReceive('getOriginal')->andReturn([
        'webhook_url' => 'https://hooks.slack.com/services/OLD_SECRET',
        'is_active' => true,
    ]);

    $this->mockAudit->shouldReceive('log')
        ->once()
        ->with(
            'updated',
            'alert_config',
            10,
            ['webhook_url' => '[REDACTED]'],
            ['webhook_url' => '[REDACTED]']
        )
        ->andReturn(1);

    $this->observer->updated($config);
    $this->addToAssertionCount(1);
}

public function test_deleted_redacts_webhook_url(): void
{
    $config = new AlertConfig([
        'location_id' => 1,
        'webhook_url' => 'https://hooks.slack.com/services/SECRET',
    ]);
    $config->id = 10;

    $this->mockAudit->shouldReceive('log')
        ->once()
        ->with('deleted', 'alert_config', 10, Mockery::on(function ($attrs) {
            return $attrs['webhook_url'] === '[REDACTED]'
                && $attrs['location_id'] === 1;
        }))
        ->andReturn(1);

    $this->observer->deleted($config);
    $this->addToAssertionCount(1);
}
```

### Also update `test_created_logs_audit_entry` — add webhook redaction test

Existing test has no webhook. Add a sibling test to lock in `created()` redaction behavior (which shipped round-2 but has no dedicated test):

```php
public function test_created_redacts_webhook_url(): void
{
    $config = new AlertConfig([
        'location_id' => 1,
        'webhook_url' => 'https://hooks.slack.com/services/SECRET',
    ]);
    $config->id = 10;

    $this->mockAudit->shouldReceive('log')
        ->once()
        ->with('created', 'alert_config', 10, Mockery::on(function ($attrs) {
            return $attrs['webhook_url'] === '[REDACTED]'
                && $attrs['location_id'] === 1;
        }))
        ->andReturn(1);

    $this->observer->created($config);
    $this->addToAssertionCount(1);
}
```

Net test delta: 3 new tests, 2 modified. Expected final count for this file: 6 tests (up from 3).

## 4. CodeRabbit reply on PR#2 — dismiss double-query finding

Locate PR#2 via `gh pr list --repo Jordanmuss99/dynamic-pterodactyl --head dp-03-audit-log-coverage --json number,url`.

Find the open CodeRabbit review comment thread about the pre-update SELECT in `confirm()` / `extend()` (search PR comments for mentions of `getByToken` or "double query" / "extra SELECT"). If no such comment exists yet in the current CodeRabbit round, skip step 4 entirely and record "CodeRabbit did not raise this on latest review; reply skipped" in the final report.

If the comment exists, post a reply via `gh api` (resolve the comment via its id from the review comments endpoint):

```
gh pr comment <pr-number> --repo Jordanmuss99/dynamic-pterodactyl --body '<body-below>'
```

### Reply body (verbatim)

```
@coderabbitai Thanks for the nudge. Closing this one as by-design.

The pre-update `getByToken()` SELECT in `confirm()` (ReservationService.php:127) and `extend()` (:190) exists specifically to capture `$reservation->id` so the audit log references the real entity id rather than `0`. The UPDATE itself still enforces the state predicate atomically via `WHERE status = 'pending'`, so there is no TOCTOU window introduced — the SELECT is purely for audit context, not for logic branching.

The alternative (restructuring to `DB::table()->first()` then conditionally updating) would add branch complexity without improving atomicity, since the predicate-guarded UPDATE is already race-safe.

The reservation confirm path is one query per customer purchase, not a hot loop, so the extra roundtrip cost is immaterial in practice. If we ever needed to eliminate it, the right move would be a stored procedure or a RETURNING clause on the UPDATE (Postgres-only), neither of which is worth the complexity here.
```

If `gh pr comment` doesn't target the specific review thread correctly, fall back to posting as a top-level PR comment — it still reaches the reviewer.

## 5. Appended note in `.sisyphus/plans/dp-04-shortfall-notifications.md`

Append a section at end of file:

```markdown
## Sidebar: DI consistency cleanup (carried over from dp-03 reviewer round-3)

During dp-03 reviewer round-3, the pattern inconsistency for `AuditLogService` resolution was flagged:

- `ReservationService` — constructor injection (correct)
- `AlertConfigObserver` — constructor injection (correct)
- `ConfigOptionSetupService.php:76` — resolves inline via `app(AuditLogService::class)` (deviant)

Fold into dp-04 because shortfall notifications will touch `AlertService`, which is adjacent to the audit integration points. While adding the shortfall email path, convert `ConfigOptionSetupService` to accept `AuditLogService` via constructor:

```php
public function __construct(private AuditLogService $audit) {}
```

...and replace the inline `app(...)` call at the audit site with `$this->audit->log(...)`. Update any `new ConfigOptionSetupService(...)` callers or `$this->app->make(...)` resolutions — check callers with `grep -r 'new ConfigOptionSetupService\|ConfigOptionSetupService::class' extensions/Others/DynamicPterodactyl/`. Testability win: removes the `app()` facade call, letting tests inject a Mockery mock directly.

Not a blocker; purely consistency.
```

## 6. Verification protocol (subagent must perform all)

After edits, before commit:

1. `php -l Models/Observers/AlertConfigObserver.php` — must print `No syntax errors detected`.
2. `php -l Services/ConfigOptionSetupService.php` — must print `No syntax errors detected`.
3. `php -l tests/Unit/AlertConfigObserverTest.php` — must print `No syntax errors detected`.
4. `../../../vendor/bin/phpunit` from extension dir — MUST pass. Expected: `OK (45 tests, ...)` (42 pre-existing + 3 new).
5. `git status -s` must show only modified: `AlertConfigObserver.php`, `ConfigOptionSetupService.php`, `AlertConfigObserverTest.php`; plus untracked `?? AGENTS.md`. No other files.

After amend + push:

6. `git log -1 --format='%H %an <%ae>'` must show the new SHA with `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`.
7. `git rev-parse HEAD` must equal `git rev-parse origin/dp-03-audit-log-coverage`.
8. `git status -s` final must be `?? AGENTS.md` only.

## 7. Final report back to orchestrator

Subagent must report verbatim:

1. New HEAD SHA on `dp-03-audit-log-coverage`
2. All three `php -l` outputs
3. PHPUnit summary line
4. Amended tip `git log -1` author line
5. `git rev-parse HEAD` vs `origin/dp-03-audit-log-coverage` (must match)
6. Final `git status -s`
7. CodeRabbit reply outcome: either PR number + URL of posted comment, OR "CodeRabbit had no open thread on this topic in current round; reply skipped"
8. Confirmation that `dp-04-shortfall-notifications.md` appended section is present

## 8. Stop conditions (abort and report instead of proceeding)

- Starting HEAD doesn't match `83d13c81...`.
- `php -l` fails on any of the three files post-edit.
- PHPUnit red.
- `git status -s` shows files other than the three modified + untracked `AGENTS.md`.
- `git push --force-with-lease` rejected (someone else pushed — reassess, don't force).
