# Merge dp-02 + dp-03 PRs → `dynamic-slider` (rebase strategy)

**Repo**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (nested fork at `Jordanmuss99/dynamic-pterodactyl`)
**Target branch**: `dynamic-slider`
**Source branches** (both force-pushed to origin, verified green, all reviewer rounds closed):
- `dp-02-http-resilience` @ `9c5dcf0b2375b6a2ef0a0926eff0fec58b05e8e4` — PR #1
- `dp-03-audit-log-coverage` @ `2af5294dc6f7341cca71ea4dc9439fd20b94c745` — PR #2
**Strategy**: `gh pr merge --rebase --delete-branch` (user-approved)
**Order**: PR #1 first, then PR #2

## Rationale for rebase strategy (for context)

Each feature branch is already a single amended commit on top of `dynamic-slider`'s current tip. `--rebase` replays that commit linearly onto the target:
- No merge commit noise
- Linear history on `dynamic-slider`
- Preserves the commit message / diff the reviewers signed off on (note: rebase onto changes the commit SHA, but content and message are identical)
- If dp-03's base shifts when dp-02 lands first, `gh pr merge --rebase` handles the second rebase automatically

No conflicts expected — dp-02 touches `Services/ResourceCalculationService.php` + its test only; dp-03 touches observers/services/listeners/controllers, none of which overlap with dp-02's surface.

## Constraints

- Work in the nested repo only. `cd /var/www/paymenter/extensions/Others/DynamicPterodactyl/` before every git/gh command.
- Git identity via flags only: `-c user.name=Jordanmuss99 -c user.email=164892154+Jordanmuss99@users.noreply.github.com`. (Note: rebase-merge on GitHub uses GH's own author attribution for the merge, but any local pull/push should still carry the correct identity.)
- `AGENTS.md` must remain untracked throughout (`?? AGENTS.md` in final `git status -s`).
- Do NOT manually rebase locally and force-push — let `gh pr merge --rebase` handle it. This ensures PR status transitions to "merged" rather than "closed".
- If `gh pr merge --rebase` reports conflicts on either PR, STOP and report — do not attempt resolution without orchestrator guidance.

## Preflight (abort if any fails)

1. `git rev-parse HEAD` while on `dp-02-http-resilience` must equal `9c5dcf0b2375b6a2ef0a0926eff0fec58b05e8e4`.
2. `git rev-parse HEAD` while on `dp-03-audit-log-coverage` must equal `2af5294dc6f7341cca71ea4dc9439fd20b94c745`.
3. Both local branches must equal their `origin/` counterparts.
4. `gh pr list --repo Jordanmuss99/dynamic-pterodactyl --state open --head dp-02-http-resilience --json number,mergeable,mergeStateStatus` must show the PR as open and mergeable (`mergeable: "MERGEABLE"`).
5. Same check for `dp-03-audit-log-coverage`.
6. Current branch check: note whichever branch is currently checked out before starting; return to it at the end (or land on `dynamic-slider` — your call).

## Execution

### Step 1 — Merge PR #1 (dp-02-http-resilience)

```bash
PR_NUM=$(gh pr list --repo Jordanmuss99/dynamic-pterodactyl --head dp-02-http-resilience --state open --json number --jq '.[0].number')
echo "Merging PR #$PR_NUM (dp-02-http-resilience)"
gh pr merge "$PR_NUM" --repo Jordanmuss99/dynamic-pterodactyl --rebase --delete-branch
```

If `gh pr merge` errors with conflict/mergeable issues: STOP. Report the error and current state.

### Step 2 — Update local `dynamic-slider`

```bash
git fetch origin --prune
git checkout dynamic-slider
git pull --ff-only origin dynamic-slider
git log -1 --format='%H %s'  # expect to see dp-02 commit message
```

### Step 3 — Verify PR #2 still mergeable (may have auto-rebased on top of new dynamic-slider)

```bash
gh pr view <pr-2-number> --repo Jordanmuss99/dynamic-pterodactyl --json mergeable,mergeStateStatus
```

If `mergeable` != `MERGEABLE`, STOP and report. GitHub may need a moment to re-evaluate after step 1; retry once after 10-20 seconds if it's `UNKNOWN`.

### Step 4 — Merge PR #2 (dp-03-audit-log-coverage)

```bash
PR_NUM_2=$(gh pr list --repo Jordanmuss99/dynamic-pterodactyl --head dp-03-audit-log-coverage --state open --json number --jq '.[0].number')
echo "Merging PR #$PR_NUM_2 (dp-03-audit-log-coverage)"
gh pr merge "$PR_NUM_2" --repo Jordanmuss99/dynamic-pterodactyl --rebase --delete-branch
```

### Step 5 — Final update + verify

```bash
git fetch origin --prune
git checkout dynamic-slider
git pull --ff-only origin dynamic-slider

# Confirm last two commits on dynamic-slider are dp-02 and dp-03
git log -3 --oneline

# Confirm feature branches are gone from remote
git branch -r | grep -E 'dp-02|dp-03' || echo "Feature branches removed from remote"

# Confirm local branches still exist but no longer have remotes
# (gh --delete-branch removes remote only, not local)
git branch | grep -E 'dp-02|dp-03'

# Run full test suite on final dynamic-slider state
php -l Services/ResourceCalculationService.php
php -l Services/ReservationService.php
php -l Services/ConfigOptionSetupService.php
php -l Models/Observers/AlertConfigObserver.php
../../../vendor/bin/phpunit

# Final status
git status -s
```

### Step 6 — Optional: clean up local feature branches

Since they're merged and remotes are deleted:

```bash
git branch -d dp-02-http-resilience
git branch -d dp-03-audit-log-coverage
```

If `-d` refuses (because local tip differs from merged-in content due to SHA changes from rebase-merge), use `-D` but only after confirming the changes are visible on `dynamic-slider` via `git log dynamic-slider --oneline -5`.

## Verification checklist (subagent must confirm all)

1. Both PRs show status "merged" on GitHub (`gh pr view <n> --json state --jq .state` returns `MERGED` for both).
2. `git log dynamic-slider --oneline -5` shows both feature commits at the tip.
3. `git rev-parse HEAD` (on dynamic-slider) == `git rev-parse origin/dynamic-slider`.
4. `php -l` clean on all four changed files from the combined surface.
5. `../../../vendor/bin/phpunit` passes with expected test count (should be ≥45 — same as dp-03 count since dp-03 is the superset).
6. Final `git status -s` shows `?? AGENTS.md` only.
7. Remote feature branches `dp-02-http-resilience` and `dp-03-audit-log-coverage` are deleted.

## Report back (verbatim)

1. Both PR numbers and their final `MERGED` state confirmation
2. New SHA of `dynamic-slider` HEAD on remote
3. Output of `git log dynamic-slider --oneline -5` showing both commits landed linearly
4. PHPUnit summary line
5. Final `git status -s`
6. Confirmation that remote feature branches are deleted

## Stop conditions

- Preflight SHA mismatches → abort, report.
- Either PR not in `MERGEABLE` state → abort, report.
- `gh pr merge` reports conflict → abort, report. Do NOT attempt manual resolution.
- PHPUnit fails on post-merge `dynamic-slider` → abort, report. (Would indicate an integration issue invisible at the individual PR level.)
- `git push` rejected at any step → abort, report. No `--force` under any circumstance.
