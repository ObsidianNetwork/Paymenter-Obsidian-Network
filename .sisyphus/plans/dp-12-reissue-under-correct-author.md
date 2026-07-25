# dp-12 Reissue Under Correct PR Author

**Scope**: resume the interrupted dp-12 re-land operation. The original PR #11 (squash `ca19e96e`) was opened under the wrong GitHub account (`ImStillBlue` instead of `Jordanmuss99`), so CodeRabbit never reviewed it. This plan finishes resetting `dynamic-slider` to pre-dp-12 state, lands the hardening docs, re-opens dp-12 as a new PR under the correct account, and runs the hardened `/ralph-loop` contract to completion.

**Type**: Process remediation + re-issue. No new feature code. Content of dp-12 is unchanged from `ca19e96e`.

**Working dir**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl` (nested git repo).

---

## Pre-conditions (already in place — verify, do not re-do)

- [x] Verify: safety tag `pre-dp12-revert-20260424` @ `38a2ca80c421f9f459306a1d5127376f7e36db0c` exists on origin. `git ls-remote origin refs/tags/pre-dp12-revert-20260424` must return that SHA.
- [x] Verify: feature branch `origin/dp-12-capacity-alerts-observability` exists, tip commit `d16247e`, author `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`, commit message subject `feat(alerts): capacity-alert delivery + scheduler + observability audit (dp-12)`. Run `git ls-remote origin refs/heads/dp-12-capacity-alerts-observability` and `gh api repos/Jordanmuss99/dynamic-pterodactyl/commits/d16247e --jq '.author.login + " " + .commit.author.email + " " + .commit.message'`.
- [x] Verify: local `dynamic-slider` HEAD is at `a4365fa` (pre-dp-12). If it's at `38a2ca8` the earlier reset was lost — re-run `git reset --hard a4365fa`.
- [x] Verify: local working tree has uncommitted changes to `CLAUDE.md` and `DECISIONS.md` only (dp-process-audit hardening from a popped stash). If stash was re-applied elsewhere or reverted, reconstruct from the original diff in `.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md` + `.sisyphus/templates/ralph-loop-contract.md`.
- [x] Verify: `gh auth status` shows `Jordanmuss99` as the active account. If not, `gh auth switch -u Jordanmuss99` then `[ "$(gh api /user --jq .login)" = "Jordanmuss99" ]` — abort on mismatch.

**If any pre-condition fails, STOP and escalate before proceeding.**

---

## Stage C — Correct the hardening docs (noreply is default, not hotmail)

**Context**: the hardening docs in the working tree (popped from stash) still say `jordanmuss@hotmail.com` is the required commit author email. GitHub just blocked a push with that address via `GH007` (the account has "Block command line pushes that expose my email" enabled). Empirical evidence from PR #9 (reviewed 3 times with noreply) vs PR #10/#11 (0 reviews also with noreply) proves commit author email is NOT the CodeRabbit determinant — PR author GitHub login is. The docs must be corrected before committing.

### C.1 — `extensions/Others/DynamicPterodactyl/CLAUDE.md`

Replace the block currently at lines 184–191 (subsection heading `**Commit author email** (secondary — belt-and-braces):` through the sentence ending `Historical commits using that form are grandfathered.`) with:

```markdown
**Commit author email** (NOT a CodeRabbit determinant — cosmetic only):

Default: use the GitHub privacy noreply form.

```bash
git config user.name "Jordanmuss99"
git config user.email "164892154+Jordanmuss99@users.noreply.github.com"
```

PR #9 (3 CodeRabbit reviews) and PRs #10, #11 (0 reviews each) all used this exact form. Empirical evidence: commit author email is not the determinant. Use noreply unless you want cleaner public attribution — in which case first disable "Block command line pushes that expose my email" at https://github.com/settings/emails, otherwise GitHub rejects the push with `GH007`.
```

Do not touch any other part of CLAUDE.md. Confirm the "PR author identity" section above this block is unchanged (it remains the primary rule).

### C.2 — `extensions/Others/DynamicPterodactyl/DECISIONS.md`

Replace the single paragraph at line 271 (starts `Commit author email (secondary):` through `PR #9 proved the noreply form doesn't break auto-review by itself.`) with:

```markdown
Commit author email (not a determinant): use the GitHub noreply form `164892154+Jordanmuss99@users.noreply.github.com`. PR #9 used noreply and received 3 CodeRabbit reviews; PRs #10, #11 used the same noreply and received 0 — proving email is not the differentiator. A plain `jordanmuss@hotmail.com` attribution is optional for cleaner public history but requires disabling "Block command line pushes that expose my email" at https://github.com/settings/emails (otherwise GitHub rejects with GH007). Historical dp-11/dp-13/dp-12 commits using the noreply form are correct and do not need rewriting.
```

Do not touch other parts of DECISIONS.md. The "PR author identity rule (PRIMARY)" sentence above this paragraph stays as-is.

### C.3 — `.sisyphus/templates/ralph-loop-contract.md` (parent repo)

The contract template also contains the hotmail guidance. Open it, find the "PR author identity" section near the top (around line 20–52), locate the subsection that reads `- CodeRabbit-linked email: jordanmuss@hotmail.com` and the `git config user.email "jordanmuss@hotmail.com"` instruction, and rewrite that subsection to match the CLAUDE.md correction above: noreply is default, hotmail is optional cosmetic preference conditional on the user disabling the GitHub email privacy block.

---

## Stage D — Commit hardening and force-push `dynamic-slider`

### D.1 — Stage and commit

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git add CLAUDE.md DECISIONS.md
git status --short  # confirm only those two files staged
git commit -m "chore(process): CodeRabbit review mandate + PR-author identity rule (dp-process-audit)

Post-incident hardening following the 2026-04-24 CodeRabbit review gap
where PRs #10 (dp-13) and #11 (dp-12) merged under the wrong GitHub
account (ImStillBlue instead of Jordanmuss99) and received zero reviews.

- CLAUDE.md: adds 'CodeRabbit Review Mandate' block citing the mechanical
  pre-merge verify script and the gh auth switch pre-check.
- DECISIONS.md: adds Decision #11 with root cause (PR author login is
  the determinant, not commit author email) and PR #9 evidence.
- Corrects earlier draft that mis-prescribed hotmail as required — PR #9
  proved noreply form works; hotmail is cosmetic preference only.

Full forensics at .sisyphus/notepads/dp-process-audit/incident-2026-04-24.md."
```

Verify commit author: `git log -1 --format='%an <%ae>'` must print `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`.

### D.2 — Force-push `dynamic-slider`

```bash
git log --oneline -4  # expect: new hardening commit, a4365fa, 8239686, 0aa0b31
git push --force-with-lease origin dynamic-slider
```

`--force-with-lease` aborts if someone else pushed to the branch since our last fetch — that's the safe form.

### D.3 — Verification

```bash
git fetch origin
git log origin/dynamic-slider --oneline -4
# Expect:
#   <new SHA>  chore(process): CodeRabbit review mandate ...
#   a4365fa    docs(progress): mark dp-13 shipped ...
#   8239686    feat(setup-wizard): atomicity ... (dp-13) (#10)
#   0aa0b31    docs(progress): mark dp-11 shipped ...
```

`ca19e96` and `38a2ca8` must no longer appear.

---

## Stage E — Close-out PR #11 and open the new dp-12 PR

### E.1 — Add a closing comment on PR #11

GitHub won't let us "close" a merged PR (merged is terminal), but we can leave an audit-trail comment. Active `gh` account must still be `Jordanmuss99`.

```bash
gh pr comment 11 --body "**Retired — opened under wrong GitHub account.**

This PR was opened while \`gh\` was authenticated as \`ImStillBlue\`, which is on the CodeRabbit Free tier. CodeRabbit saw the PR as Free-tier-authored and skipped review entirely (0 reviews submitted; compare PR #9 which received 3 reviews under the correct \`Jordanmuss99\` account with identical commit authors).

The merge commit \`ca19e96\` has been removed from \`dynamic-slider\` (history rewritten via force-push; pre-revert snapshot preserved at tag \`pre-dp12-revert-20260424\` = \`38a2ca80\`).

The dp-12 content has been re-opened as a new PR under \`Jordanmuss99\` and will undergo the full \`/ralph-loop\` contract with the hardened pre-merge verify gate. Forensics: \`.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md\`."
```

### E.2 — Open the new PR

```bash
# Re-verify gh auth immediately before gh pr create
login=$(gh api /user --jq .login)
[ "$login" = "Jordanmuss99" ] || { echo "ABORT: gh active user is $login, need Jordanmuss99"; exit 2; }

gh pr create \
  --base dynamic-slider \
  --head dp-12-capacity-alerts-observability \
  --title "feat(alerts): capacity-alert delivery + scheduler + observability audit (dp-12)" \
  --body "Reissue of #11 under the correct \`Jordanmuss99\` account so CodeRabbit actually reviews it (the original PR was opened as \`ImStillBlue\` and CodeRabbit skipped review — see \`.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md\`).

Code content is unchanged from the original \`ca19e96\` squash (115 tests passing, 1 skipped). Cherry-picked and re-authored as a single commit \`d16247e\`.

Covers:
- Real capacity-alert email delivery (\`CapacityAlertNotification\`) replacing the logging stub.
- \`AlertService::checkCapacityAlerts()\` scheduled every 5 minutes with \`withoutOverlapping()\`.
- Capacity-alert audit trail via the \`AuditsExtensionActions\` trait (dp-13 shared trait).
- Reservation state-transition audit rows (\`confirm\` / \`cancel\` / \`cleanupExpired\`).
- Docs: DECISIONS.md, 09-IMPLEMENTATION.md, CHANGELOG.md.

### Merge gate

This PR will NOT be merged until \`.sisyphus/templates/ralph-loop-verify.sh <N>\` exits 0 (at least one CodeRabbit review submitted, latest review post-dates last commit, zero unresolved threads, \`mergeStateStatus == CLEAN\`, actionable comments = 0, PR author is \`Jordanmuss99\`). See the Review Mandate in CLAUDE.md."
```

Capture the new PR number for later stages: `NEW_PR=$(gh pr view --json number --jq .number)`.

### E.3 — Independently verify the new PR's author

```bash
gh pr view "$NEW_PR" --json author,createdAt,headRefName --jq '{author: .author.login, createdAt, head: .headRefName}'
```

`author` MUST be `Jordanmuss99`. If it's anything else, delete the PR (`gh pr close "$NEW_PR" --delete-branch` — no, that deletes the branch; use `gh api -X DELETE repos/:owner/:repo/pulls/:num` carefully), fix `gh auth`, and redo E.2.

---

## Stage F — /ralph-loop until merge

Follow `.sisyphus/templates/ralph-loop-contract.md` verbatim for this PR. Key mechanics on CodeRabbit Free plan:

1. **Wait for CodeRabbit auto-review on PR open** (typical 3–8 min, up to 15). Poll every 60s with:
   ```bash
   gh pr view "$NEW_PR" --json reviews \
     --jq '[.reviews[] | select(.author.login=="coderabbitai")] | length'
   ```
   Do nothing until this returns ≥ 1.

2. **Read the review.** For each finding:
   - If relevant + agree: make the change, commit with `Jordanmuss99 <noreply>` author, push. Wait for CodeRabbit to auto-re-review (the push triggers it). Loop.
   - If relevant + disagree: leave a plain GitHub comment (NOT `@coderabbitai` — that's Pro chat) explaining why, resolve the thread. Document rationale in the commit that accompanies the thread resolution, if any.
   - If out-of-scope: append to the correct destination plan's "Deferred" section, comment on the thread `Acknowledged. Out of scope for dp-12; deferred to dp-NN. See <plan link>.`, resolve.

3. **Do not merge until `ralph-loop-verify.sh "$NEW_PR"` exits 0.** Run it immediately before `gh pr merge`. If it fails, do not bypass — fix the failing condition.

4. **Merge**:
   ```bash
   /var/www/paymenter/.sisyphus/templates/ralph-loop-verify.sh "$NEW_PR"
   gh pr merge "$NEW_PR" --squash --delete-branch
   ```

---

## Stage G — Post-merge bookkeeping

### G.1 — Pull and capture the new squash SHA

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git checkout dynamic-slider
git pull --ff-only
NEW_SQUASH=$(git log --oneline -1 --format='%h')
echo "New dp-12 squash SHA: $NEW_SQUASH"
```

### G.2 — Update PROGRESS.md

Append to the "Current Status" table a new row for dp-12 referencing the new squash SHA and the new PR number. If there's already an old dp-12 row referencing the retired `ca19e96` / PR #11, replace it in place (do not leave both — the old one is historically misleading).

Commit:
```bash
git add PROGRESS.md
git commit -m "docs(progress): mark dp-12 re-shipped under correct PR author (squash $NEW_SQUASH, fork PR #$NEW_PR, supersedes #11)"
git push origin dynamic-slider
```

### G.3 — Archive boulder state

```bash
cp /var/www/paymenter/.sisyphus/boulder.json \
   /var/www/paymenter/.sisyphus/completed/dp-12-reissue-under-correct-author.boulder.json
rm /var/www/paymenter/.sisyphus/boulder.json
```

If no active `boulder.json` exists (because this plan wasn't run via `/start-work`), skip.

### G.4 — Final verification

```bash
gh pr view "$NEW_PR" --json mergedAt,mergeCommit,author,reviews \
  --jq '{mergedAt, squash: .mergeCommit.oid[:7], author: .author.login, cr_reviews: ([.reviews[] | select(.author.login=="coderabbitai")] | length)}'
```

All fields must be present and sensible: `author = Jordanmuss99`, `cr_reviews >= 1`, `mergedAt` populated.

---

## Acceptance criteria

- [x] `origin/dynamic-slider` tip contains the hardening commit directly above `a4365fa`; `ca19e96` and `38a2ca8` are gone from the branch.
- [x] `origin` tag `pre-dp12-revert-20260424` still present at `38a2ca80`.
- [x] PR #11 has a closing comment explaining the retirement and linking the new PR.
- [x] A new PR exists against `dynamic-slider` authored by `Jordanmuss99`, containing `d16247e` (re-authored dp-12 content).
- [x] At least one CodeRabbit review has been submitted on the new PR.
- [ ] `ralph-loop-verify.sh <NEW_PR>` exits 0.
- [ ] The new PR is merged; `PROGRESS.md` has a row with the new squash SHA replacing the old dp-12 row.
- [ ] `.sisyphus/boulder.json` archived if one exists for this plan.
- [ ] Final test suite on `dynamic-slider` passes: 115 tests (same as the retired squash), 1 skipped.

---

## Rollback path (if everything goes wrong before the new PR merges)

The safety tag `pre-dp12-revert-20260424` at `38a2ca80` is the full-state snapshot of `origin/dynamic-slider` just before this operation. To restore:

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git fetch origin --tags
git checkout dynamic-slider
git reset --hard pre-dp12-revert-20260424
git push --force-with-lease origin dynamic-slider
git push origin --delete dp-12-capacity-alerts-observability
# If a new PR was already opened, close it:
# gh pr close <NEW_PR> --delete-branch --comment "Aborting reissue; restoring pre-incident state. See .sisyphus/notepads/dp-process-audit/incident-2026-04-24.md"
```

PR #11 stays in merged state (GitHub won't un-merge). The pre-revert tag is the primary rollback anchor.

---

## Out of scope

- Re-litigating dp-13 (PR #10). That PR also merged without CodeRabbit review (different account issue), but the user explicitly scoped this remediation to dp-12 only. dp-13 stays as-shipped.
- CodeRabbit Pro upgrade or plan-tier changes.
- The parent repo (`/var/www/paymenter`) uncommitted changes to `FORK-NOTES.md` and the new `.sisyphus/templates/`, `.sisyphus/notepads/dp-process-audit/` files. Those are parent-repo concerns, independent of this extension re-issue. Handle in a separate parent-repo commit if desired.
- Any new dp-12 feature work. Content is unchanged from `ca19e96`.

---

## Delegation

Category: `quick` or `build` (not `deep` — no new code). One subagent runs all stages sequentially on the existing feature branch + `dynamic-slider`.

Subagent MUST:

1. Verify every pre-condition in the list above before mutating anything. Abort on any failure.
2. Re-verify `gh` active account is `Jordanmuss99` immediately before `gh pr create` and `gh pr comment` — not just once at the start.
3. Use `--force-with-lease` (not `--force`) when pushing `dynamic-slider`.
4. Keep author on every commit at `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`. Verify with `git log -1 --format='%an <%ae>'` after every commit.
5. Never run `php artisan migrate:fresh` / `migrate:reset` / `db:wipe` / any destructive artisan command. This plan has zero reason to touch the DB. If something seems to need it, STOP and escalate.
6. Run `/var/www/paymenter/.sisyphus/templates/ralph-loop-verify.sh <PR_NUMBER>` as the final gate before `gh pr merge`. If it exits non-zero, do NOT bypass — fix the failing condition.
7. When CodeRabbit's auto-review arrives, apply the out-of-scope protocol from DECISIONS.md for findings that don't belong in dp-12.
