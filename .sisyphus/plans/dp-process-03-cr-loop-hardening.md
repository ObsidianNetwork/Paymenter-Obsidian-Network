# dp-process-03 — CR Loop Hardening (CLI-first, accept-side comments, quiet period)

**Scope**: `/var/www/paymenter/` (Paymenter fork) + `Jordanmuss99/dynamic-pterodactyl` extension. Outer Paymenter holds the canonical contract: `.sisyphus/templates/ralph-loop-contract.md` and `.sisyphus/templates/ralph-loop-verify.sh` (P1-P3). Phase P4 creates `.sisyphus/` in the extension repo with copies of both files (drift-checked). Default packaging: single PR per repo; outer-repo PR may split into 3 phase-PRs per the §Proposed Design heuristic.
**Type**: Process tightening. Closes 3 gaps (pre-PR CLI, accept-side comments, post-approval quiet period) + adds cross-repo sync (extension repo) + post-merge violation handling. Surfaced during dp-13 -> dp-19 execution.
**Predecessor**: `.sisyphus/plans/dp-process-02-ralph-loop-v2-enhancements.md` (complete, shipped).
**Effort**: M (~4 hours total — contract edits + verify.sh changes + extension-repo `.sisyphus/` seed + self-tests against >=3 historical PRs).

---

## Context — what surfaced over dp-13 to dp-19

Over the last 7 dp-NN cycles, three contract weaknesses were observed:

1. **dp-19 bypass**: branch `dynamic-slider/1.4.7` IS the default branch on the outer Paymenter repo. The contract requires "base branch is an integration branch, not a default" but offers no escape when the integration branch IS the default. The driver shipped dp-19 by committing directly to default + running `coderabbit review` post-hoc on `HEAD~1`. No PR existed → no auto-review, no thread workflow, no formal CR approval. This is a real gap, not an edge case — the same will recur on every fork that uses a non-`master`/`main` integration branch as its working default.

2. **Silent thread resolution**: Across dp-13/14/17/18/19 PRs, accepted CR findings were addressed by pushing a fix commit and resolving the thread WITHOUT replying. The contract says "never close a thread without a reply" but the §Evaluating-findings prose treats "push the fix" as the action — there's no explicit "post a comment that says you applied it". Verify.sh checks resolved-status only, not reply-presence. CR's learnings model (per docs.coderabbit.ai/knowledge-base/learnings) needs feedback to learn the team's accept/reject patterns; silent resolution starves it. Last evidence of explicit accept-comment: PR #15 (https://github.com/Jordanmuss99/dynamic-pterodactyl/pull/15). Stopped after.

3. **Pre-emptive merge after CR approval**: At least 2 dp-NN PRs were merged within minutes of CR's approval, before CR's follow-up auto-review pass on the final commit completed. CR's approval is not final — it can post additional findings within 5-10 min after the formal APPROVED status, especially when the final commit triggers another incremental review. Verify.sh treats `CodeRabbit status == pass` + `mergeStateStatus == CLEAN` + zero unresolved threads as merge-permitted; there's no time-based guard.

---

## Problem

The /ralph-loop contract permits three failure modes that bypass critical-evaluation:

| Failure mode | Mechanism | Past incidence |
|---|---|---|
| Skip the PR-review loop entirely | Default-branch shortcut when integration branch IS default | dp-19 (1 confirmed) |
| Resolve CR threads silently | Push fix commit, click resolve, no thread reply | dp-13/14/17/18/19 (5 confirmed) |
| Merge inside CR's follow-up window | Verify.sh has no quiet-period guard after approval | dp-NN history (≥2 suspected; not auditable) |

All three are mechanical gaps — the contract's intent is clear but the script doesn't enforce, and the prose has wiggle room.

---

## Goal

Three closure rules + verify.sh checks (P1-P3), plus cross-repo sync (P4) and post-merge violation handling. Each check has an explicit, audited override flag where appropriate; each failure mode becomes mechanically blocked without override:

1. Pre-PR local `cr review` is REQUIRED for every dp-NN. Direct-to-default push for dp-NN work is BLOCKED unless explicitly allowed.
2. Every CR review thread MUST have ≥1 Jordanmuss99 reply (accept ack OR reject rationale) before resolution.
3. ≥10-minute quiet period after CR's most recent activity before merge is permitted.
4. Extension repo gets a `.sisyphus/templates/` directory seeded from the outer canonical, with a `--check-sync` drift detector (P4).
5. Post-merge violations are tracked observationally (PROGRESS.md note + follow-up dp-NN plan), not auto-reverted; >=3 recurrences of the same rule within a quarter trigger a hardening plan.

All three checked by `ralph-loop-verify.sh`. Each check has a documented escape hatch (logged to the existing waivers audit file).

---

## Out of scope

- Retroactively re-PR'ing dp-1 through dp-19. Rules apply to dp-20+ only.
- Changing CR config (`.coderabbit.yaml`) — separate concern, dp-process-02 already shipped.
- Branch-protection rules at the GitHub level. Repo-admin work; out of scope here.
- Opening this contract to non-Jordanmuss99 reviewers / multi-reviewer flows.
- Replacing `cr` CLI with a different review tool.
- Auto-replying to CR threads via bot — every reply is a human/agent decision.

---

## Proposed Design

Three independently shippable phases. Order: **P1 → P2 → P3**. Each ships as one PR per repo touched (only the outer repo holds the contract + verify.sh, so really one PR per phase total).

| Phase | What | Effort | Order rationale |
|---|---|---|---|
| **P1** | Mandatory pre-PR CLI + integration-branch enforcement | 1h | Highest-leverage; closes dp-19-style bypass; everything downstream presumes a PR exists |
| **P2** | Mandatory comment-then-resolve on every CR thread | 1h | Independent of P1; verify.sh check is a discrete GraphQL query |
| **P3** | 10-min quiet period after last CR activity | 1h | Independent of P1/P2; verify.sh check is a timestamp comparison |

All three contract phases (plus P4 extension sync) ship to `.sisyphus/templates/` in the outer Paymenter repo plus a new `.sisyphus/` in the extension repo. **Packaging heuristic**: default to a single outer-repo PR; split into three phase-PRs only when the cumulative diff exceeds ~300 changed lines OR the change set spans both contract templates AND both `AGENTS.md`/`CLAUDE.md` files (i.e., crosses the templates+docs boundary). The extension-side P4 work is always its own PR (separate repo, separate audit). Verify.sh self-tests run against >=3 recent historical PRs (e.g., #15, #18, #19, #20) to confirm the new checks would have caught past failures and don't false-positive on legitimate flows.

---

## Phase P1 — Mandatory pre-PR CLI + integration-branch enforcement

**Goal**: every dp-NN goes through implement → `cr review` clean → branch + PR → CR auto-review loop → merge. No more direct-to-default shortcuts.

### Contract changes (`ralph-loop-contract.md`)

- §Hard rules — add **Rule 6**:
  > **6. Every dp-NN PR targets an integration branch off the default; direct push to a default branch for dp-NN work is a contract violation.** "Default branch" = whatever `gh repo view --json defaultBranchRef --jq .defaultBranchRef.name` returns at the time of work. If the integration branch on a fork IS the default (e.g., `dynamic-slider/1.4.7` on `ObsidianNetwork/Paymenter-Obsidian-Network`), create a feature branch off it (e.g., `dp-NN-<slug>`) and PR back to it.
- §Loop protocol — replace the `push commit` opening step with:
  ```
  implement on feature branch (off integration branch, off default)
    └─ run `cr review --plain --type committed --base <integration-branch>`
       │  (REQUIRED — see §Tooling. Must exit 0 OR all findings addressed/rejected with rationale.)
       │
       ├─ findings present? → fix locally → re-run cr review → repeat
       └─ clean → `gh pr create --base <integration-branch>`
            └─ wait up to 60s for 'CodeRabbit' status = pending
               (rest of the existing loop unchanged)
  ```
- §Loop protocol — also append the verbatim 8-step driver checklist after the protocol diagram for at-a-glance clarity (mirrors the user's exact phrasing of the gate sequence):
  ```
  Step 1. Finish current plan code changes or fixes for new findings.
  Step 2. Run `cr review --plain --type committed` (CodeRabbit CLI) locally on the working branch.
  Step 3. CR CLI verification clean?
  Step 4. If NO: go back to Step 1 and fix. Else if YES: proceed to Step 5.
  Step 5. Create PR (`gh pr create --base <integration-branch>`).
  Step 6. Run the CR PR-review loop (auto-review -> reply on threads -> resolve -> re-trigger as needed).
  Step 7. CR PR loop reaches APPROVED with all threads resolved AND no further issues come back after approval (Rule 8 quiet period satisfied)?
  Step 8. If YES: merge PR to integration branch.
  ```
- §Tooling — promote the pre-push CLI section from "RECOMMENDED" to "REQUIRED for every dp-NN PR before `gh pr create`". Drop the >2-files / security / infra qualifier — apply universally to dp-NN work.
- §Mechanical-gate — note that `--expected-base` regex must NOT match the default branch unless `--allow-direct-default` is supplied with rationale.

### Verify.sh changes (`ralph-loop-verify.sh`)

- Detect when the PR's base branch equals the repo's default branch:
  ```bash
  default=$(gh repo view "$repo" --json defaultBranchRef --jq .defaultBranchRef.name)
  base=$(gh pr view "$pr" --repo "$repo" --json baseRefName --jq .baseRefName)
  if [ "$base" = "$default" ] && [ -z "$ALLOW_DIRECT_DEFAULT" ]; then
    echo "FAIL: PR $pr targets default branch ($default). Use feature branch off default, or pass --allow-direct-default --reason '...' for bootstrap PRs."
    exit 1
  fi
  ```
- Add `--allow-direct-default` flag (analogous to existing `--allow-actionable`); requires `--reason` with rationale; logs to `.sisyphus/notepads/ralph-loop-waivers.jsonl`.
- Legitimate uses of `--allow-direct-default`: bootstrap PR that creates the very first integration branch on a new repo (rare). NOT for "the integration branch IS the default" — that case must use a feature branch off default.

### Driver workflow change

When working on a repo whose integration branch IS the default (the dp-19 case), the driver MUST:
1. Create a feature branch: `git checkout -b dp-NN-<slug>` off the integration branch (which happens to be default).
2. Implement, commit, push the feature branch.
3. Open PR with base = integration branch (which happens to be default).
4. Run the CR auto-review loop normally.
5. Merge the feature branch back to integration/default.

This adds ~3 commands to the driver workflow but closes the bypass.

---

## Phase P2 — Mandatory comment-then-resolve on every CR thread

**Goal**: every CR review thread receives an explicit Jordanmuss99 reply BEFORE being resolved. Both accept and reject paths.

### Contract changes (`ralph-loop-contract.md`)

- §Hard rules — add **Rule 7**:
  > **7. Every CR-authored review thread has ≥1 Jordanmuss99 reply before resolution.** Silent resolution (clicking "Resolve" without posting a reply comment) is a violation. Applies symmetrically: accept-side replies summarize what was applied; reject-side replies provide the 3-part rationale.
- §Evaluating findings — make accept-side commenting explicit. Replace the current "If you agree → fix it" subsection with:
  > **If you agree → fix it AND comment on the thread.**
  > 1. Push a follow-up commit that addresses the finding.
  > 2. Reply on the CR thread with the template:
  >    ```
  >    Applied in <short-sha>: <one-line summary of the change>.
  >    ```
  >    Example: `Applied in 9897b06: extracted Alpine fallback x-data so error/status are defined for non-slider products.`
  > 3. Resolve the thread.
  > Auto-review fires on the new commit; wait for `CodeRabbit` status to return to `pass`.
- §Evaluating findings — keep the existing reject-side subsection as-is (already requires 3-part reply); add a sentence: "The reject-side reply MUST be posted as a thread reply (not as a top-level PR comment) so it links to the finding it addresses."
- §Evaluating findings — add a **nitpick-handling carve-out**:
  > **Nitpick (`nitpick`-tagged) findings are partially exempt from the accept-side comment requirement.** If you AGREE with a nitpick: push the fix and resolve the thread; an accept-side comment is OPTIONAL (silent accept is acceptable for nits only). If you DISAGREE with a nitpick: post the standard 3-part reject reply BEFORE resolving — disagreement comments help CR's learnings model reduce future low-value nits, which is the highest-leverage feedback we can give it. Rule 7 still applies in full force to all NON-nit findings on both accept and reject paths.

### Verify.sh changes (`ralph-loop-verify.sh`)

- For every unresolved-OR-resolved review thread authored by `coderabbitai`, fetch the thread's comments and verify ≥1 comment authored by `Jordanmuss99` exists in the thread:
  ```bash
  gh api graphql -f query='
    query($o:String!,$r:String!,$p:Int!){
      repository(owner:$o,name:$r){
        pullRequest(number:$p){
          reviewThreads(first:100){
            nodes{
              id
              isResolved
              comments(first:100){
                nodes{ author{ login } body }
              }
            }
          }
        }
      }
    }' -F o="$owner" -F r="$rname" -F p="$pr" | \
    jq -r '
      .data.repository.pullRequest.reviewThreads.nodes[] |
      # match threads with any CR-authored comment (both `coderabbitai` and `coderabbitai[bot]` login variants)
      select(any(.comments.nodes[]; (.author.login == "coderabbitai" or .author.login == "coderabbitai[bot]"))) |
      # exclude threads that already have a Jordanmuss99 reply
      select(all(.comments.nodes[]; .author.login != "Jordanmuss99")) |
      # exclude nitpick-tagged threads per the carve-out at lines 154-155 (silent-accept OK for nits)
      # detection: body of the FIRST CR-authored comment in the thread contains the case-insensitive token "nitpick"
      # (using `[.comments.nodes[] | select(CR-login)][0]` rather than `.comments.nodes[0]` so we inspect the first CR comment
      #  even when a non-CR comment precedes it; pre-flight sampling under our .coderabbit.yaml profile confirms the marker)
      select((([.comments.nodes[] | select(.author.login=="coderabbitai" or .author.login=="coderabbitai[bot]")][0].body) // "") | test("(?i)nitpick") | not) |
      .id
    '
  ```
- Any thread ID returned = violation; print the IDs and exit non-zero.
- This check runs alongside the existing "zero unresolved threads" check; both must pass.

### Notes

- The check is "thread has any Jordanmuss99 comment", not "thread's last comment is from Jordanmuss99". Keeps it tolerant of CR's auto-acks on `@coderabbitai` mentions following our reply.
- The jq filter above treats both `coderabbitai` and `coderabbitai[bot]` as CR via `(.author.login == "coderabbitai" or .author.login == "coderabbitai[bot]")`. During pre-flight sampling, confirm which variant CR uses on this repo (both are observed in different deployments) so the filter is provably correct.
- The nit-exclusion predicate `select((([.comments.nodes[] | select(.author.login=="coderabbitai" or .author.login=="coderabbitai[bot]")][0].body) // "") | test("(?i)nitpick") | not)` checks the body of the FIRST CR-authored comment in the thread (not `.comments.nodes[0]`, which could be a manual reviewer comment that CR replied to) for the case-insensitive token "nitpick". CR's CHILL profile (per our `.coderabbit.yaml`) typically posts nitpicks INSIDE the review-summary body's collapsible section rather than as separate review threads — meaning Rule 7 wouldn't flag them at all under current config. The thread-level nit-exclusion only kicks in if CR's behavior changes (e.g., profile flip to ASSERTIVE) and nits start arriving as threads. Pre-flight sampling against PR #15/#18/#19/#20 will confirm CR's actual nit-threading behavior under our profile and adjust the regex/predicate location if needed.

---

## Phase P3 — 10-minute quiet period after last CR activity

**Goal**: after CR's most recent comment, review submission, or status change, require ≥10 minutes of silence before merge is permitted. Catches CR's late follow-up findings after an apparent approval.

### Contract changes (`ralph-loop-contract.md`)

- §Hard rules — add **Rule 8**:
  > **8. ≥10-minute quiet period after most recent CR activity before merge.** "CR activity" = any review submitted or thread comment authored by `coderabbitai` since the last commit on the PR. (Earlier drafts also listed `CodeRabbit` GitHub-status flips, but they are redundant with comment/review timestamps: the PENDING→SUCCESS flip happens concurrently with CR's final review post per commit, and PENDING itself is gated upstream by the existing pre-quiet-period precondition that requires `CodeRabbit` status to be SUCCESS before the gate is even evaluated.) The verify script computes `now - last_cr_activity_timestamp` and fails if < 600s. CR's auto-ack messages ("Actions performed: Review triggered.", "Actions performed: Comments resolved.") are excluded from "activity" via body-text filter.
  >
  > **Why this rule exists (do not rush)**: CR's APPROVED status is not final. CR may post additional findings within 5-10 minutes after approval, especially when our final commit triggers an incremental review. Merging inside this window has caused us to ship code that CR would have flagged. The rule says "do not rush" — even when the dashboard shows GREEN status and zero unresolved threads, wait the full quiet period before merging. The driver SHOULD use the verify.sh `--wait` flag (Phase P3) to make the wait automatic rather than re-running manually.
- §Loop protocol — add a step before the verify.sh call:
  > After all threads resolved + `CodeRabbit` status = pass: poll `gh api` for CR's latest activity timestamp. If `now - last_cr_activity < 600s`, sleep until threshold then re-poll. Only run verify.sh once the quiet period is satisfied.
- §Loop protocol — add a **post-approval-change subprotocol** (safety guard for late CR comments after APPROVED):
  > **If CR posts findings AFTER its APPROVED status and we make code changes in response (pre-merge):**
  > 1. Push the fix commit.
  > 2. Comment `@coderabbitai full review` on the PR to formally re-trigger review (do not rely on auto-review of the new commit alone — auto-review may skip the new commit if CR's last formal status was APPROVED, leaving a stale-approval state).
  > 3. Wait for new APPROVED status + Rule 8 quiet period before merge.
  >
  > This subprotocol is the forward-fix mechanism for late findings BEFORE merge; pairs with Rule 8 to close the post-approval gap. If late findings appear AFTER merge has already happened, fall through to §Post-merge violation handling.

### Verify.sh changes (`ralph-loop-verify.sh`)

- New check (runs after thread + status checks pass):
  ```bash
  quiet_seconds=${QUIET_PERIOD_SECONDS:-600}
  last_cr=$(gh api graphql -f query='
    query($o:String!,$r:String!,$p:Int!){
      repository(owner:$o,name:$r){
        pullRequest(number:$p){
          commits(last:1){ nodes{ commit{ committedDate } } }
          comments(first:100){ nodes{ author{login} body createdAt } }
          reviews(first:100){ nodes{ author{login} submittedAt } }
        }
      }
    }' -F o="$owner" -F r="$rname" -F p="$pr" | \
    jq -r '
      (.data.repository.pullRequest.commits.nodes[0].commit.committedDate) as $last_commit
      | [
          (.data.repository.pullRequest.comments.nodes[]
            | select(.author.login=="coderabbitai" or .author.login=="coderabbitai[bot]")
            | select(.body | startswith("Actions performed:") | not)
            | select(.body | contains("@coderabbitai pause") | not)
            | select(.createdAt >= $last_commit)
            | .createdAt),
          (.data.repository.pullRequest.reviews.nodes[]
            | select(.author.login=="coderabbitai" or .author.login=="coderabbitai[bot]")
            | select(.submittedAt >= $last_commit)
            | .submittedAt)
        ] | max // empty')

  if [ -n "$last_cr" ]; then
    age=$(( $(date +%s) - $(date -d "$last_cr" +%s) ))
    if [ "$age" -lt "$quiet_seconds" ]; then
      remaining=$(( quiet_seconds - age ))
      echo "FAIL: CR activity at $last_cr is ${age}s ago; quiet period requires ${quiet_seconds}s. Wait ${remaining}s and re-run."
      exit 1
    fi
  fi
  ```
- Add `--quiet-period-seconds <N>` flag (default 600); permit override via env `QUIET_PERIOD_SECONDS` for self-tests.
- Add `--wait` flag: instead of failing immediately when the quiet period is unsatisfied, sleep until the threshold is met then re-check (driver-friendly mode that keeps the same gate but eliminates manual re-runs). Default behavior remains hard-fail for safety; `--wait` is opt-in and complements the "do not rush" rationale on Rule 8.
- Add `--skip-quiet-period` (gated by `--reason`) for emergency hotfixes; logs to waivers file.

### Edge cases

- Repo-incident PRs that explicitly used `@coderabbitai pause` should not stick on quiet-period — those PRs intentionally suppress CR. The jq filter above excludes any comment containing `@coderabbitai pause` from the activity-timestamp computation; once `pause` is set, CR posts no further activity and the quiet period naturally elapses on the prior commit's timeline. `--dry-run` MUST additionally print whether the most recent excluded comment was a `pause` directive (vs an `Actions performed:` ack) so debugging is deterministic when the filter behavior is in question.
- The exclusion filter for auto-acks must match CR's actual ack-message format. During implementation, sample 5 recent CR ack comments and confirm the `startswith("Actions performed:")` filter catches them all.

---

## Phase P4 — Cross-repo sync (extension repo)

**Goal**: the contract + verify.sh live canonically in outer Paymenter, but the extension repo (`extensions/Others/DynamicPterodactyl/`, nested git with its own `.git/`) needs the same workflow when its PRs are reviewed by CR. Without a copy in the extension's own `.sisyphus/`, extension-side discipline relies entirely on driver memory of the outer rules — fragile and undermines D-rule consistency.

### Files to create in the extension repo

The extension repo currently has no `.sisyphus/` directory. Phase P4 creates:

- `.sisyphus/templates/ralph-loop-contract.md` — copy of outer canonical (post-P3 state)
- `.sisyphus/templates/ralph-loop-verify.sh` — copy of outer canonical (post-P3 state)
- `.sisyphus/templates/SYNC.md` — short note: "These files are copies of `/var/www/paymenter/.sisyphus/templates/`. Run `bash .sisyphus/templates/ralph-loop-verify.sh --check-sync` to detect drift. Update only via dp-process-NN plan that changes both copies in lockstep."

### Drift detection

Add to verify.sh a `--check-sync` mode with a **3-tier fallback** for locating the canonical source:

1. **Primary — live diff against canonical path.** Read env var `PAYMENTER_CANONICAL_PATH` (default `/var/www/paymenter/.sisyphus/templates/`). If readable, `diff` each extension-side file against its canonical counterpart. Exit non-zero on any difference.
2. **Fallback A — stored sha256 hashes.** If `$PAYMENTER_CANONICAL_PATH` is unset, missing, or unreadable (CI sandbox, agent environment without an outer-repo mount), read sha256 hashes from `.sisyphus/templates/SYNC.md` under a `## Hashes` block (one `<filename>: <sha256>` line per file). Compute live sha256 of each extension-side file and compare. SYNC.md hashes are updated by the SAME dp-process-NN plan that changes the canonical contract — atomic update is enforced by §Post-merge violation handling.
3. **Fallback B — hard-fail.** If neither the primary path nor SYNC.md hashes are available, exit non-zero with: `FAIL: --check-sync requires PAYMENTER_CANONICAL_PATH (path to outer-repo .sisyphus/templates/) or sha256 hashes in .sisyphus/templates/SYNC.md. Cannot verify drift. Sync manually from outer Paymenter, or skip --check-sync.`

Driver behavior on detected drift (Tier 1 or Tier 2 mismatch): open a sync PR copying the outer-canonical version into the extension; do NOT modify the extension copy independently. CI configuration: either checkout outer Paymenter into a known path and set `PAYMENTER_CANONICAL_PATH`, or rely on Fallback A by ensuring SYNC.md is updated atomically with each canonical-contract change.

### Why copy and not symlink

Symlinks across the nested-git boundary risk being committed as symlink files in git, which creates cross-repo coupling that confuses contributors who clone only the extension. Copies + automated drift check is more robust and survives independent clones.

### Order

P4 ships AFTER P1/P2/P3 are merged in outer Paymenter — copies are seeded from the post-P3 canonical. Otherwise P4 creates copies of the OLD contract.

### CLAUDE.md interaction (FAIL rule alignment)

Per outer Paymenter `CLAUDE.md`, commits inside `extensions/Others/DynamicPterodactyl/` MUST come from the nested repo, not the outer working tree. P4 file creation in the extension repo follows that rule:

```
cd extensions/Others/DynamicPterodactyl
git checkout -b dp-process-03-p4-sync   # off extension default (dynamic-slider) -> per Rule 6
mkdir -p .sisyphus/templates
cp ../../../.sisyphus/templates/ralph-loop-contract.md .sisyphus/templates/
cp ../../../.sisyphus/templates/ralph-loop-verify.sh   .sisyphus/templates/
# write SYNC.md
git add .sisyphus/
git commit -m 'chore(process): seed .sisyphus/ from outer canonical (dp-process-03 P4)'
gh pr create --base dynamic-slider --title 'chore(process): cross-repo sync of CR loop contract (dp-process-03 P4)'
```

---

## Verification — self-tests against historical PRs

Before merging dp-process-03, run the updated verify.sh against:

| PR | Repo | Expected verify.sh result | Why |
|---|---|---|---|
| #18 (dp-17) | extension | Pass on Rules 1-5; FAIL on Rule 7 (silent thread resolution); demonstrate Rule 7 catches the past pattern | Establishes Rule 7 catches what we missed |
| #19 (dp-16) | extension | Pass on all rules | Sanity check — Rule 7 should not false-positive when threads had replies |
| #20 (dp-18) | extension | Pass on Rules 1-5; FAIL on Rule 7 if applicable; pass on Rules 6/8 | Independent confirmation |
| (dp-19 commits on default) | outer | Would have FAILed Rule 6; document this as the canonical "what we're closing" example | Drives the rule-6 design |

For each, run:
```bash
bash .sisyphus/templates/ralph-loop-verify.sh <PR_NUMBER> --repo <repo> --expected-base '<regex>' --dry-run
```

Add `--dry-run` flag to verify.sh: runs all checks, reports pass/fail for each rule, but does NOT exit non-zero (so the script can be used for retroactive auditing without blocking).

---

## Risks

- **False positives on Rule 7 (thread reply check)**: CR sometimes posts informational comments not tied to a finding (walkthrough, summary). The GraphQL query filters by `coderabbitai`-authored threads only — a top-level CR comment is not a "thread" so won't trigger the check. Verify this distinction during implementation.
- **False positives on Rule 8 (quiet period)**: CR's auto-ack messages ("Actions performed: ...") count as comment activity if not filtered. The body-text filter handles this but must match CR's actual ack format. If CR changes its ack wording, the filter breaks silently. Mitigation: log every excluded comment in `--dry-run` output so future drift is visible.
- **Rate-limit pressure from polling**: the quiet-period check polls every ≤30s during the wait. Pro+ has 100 chat/hr but `gh api graphql` calls don't count against CR limits — they're GitHub limits (5000/hr authenticated). Negligible at our usage.
- **Driver friction**: 3 new gates increase cycle time per PR. Estimated +5-15 min per dp-NN (CLI run + thread reply discipline + quiet-period wait). Acceptable given the failure modes being closed.
- **Rule 6 fork shadow**: if a future fork/repo enters the workflow with an unusual default-branch convention, Rule 6 may surprise the driver. Mitigation: the contract's prose for Rule 6 explicitly handles the "integration branch IS default" case with a feature-branch workflow; no escape needed for normal cases.
- **`--allow-direct-default` abuse**: this flag exists for genuine bootstrap PRs. To prevent reuse as a routine bypass, the waivers audit log entry MUST include a one-sentence justification, and dp-process audits should cross-check the log every quarter.
- **Self-test on PR #18/19/20 may be inconclusive**: the historical thread data must still be queryable via GraphQL. GitHub retains review thread data indefinitely so this should hold, but verify before relying on the test.

---

## Post-merge violation handling

If a contract violation is detected AFTER the offending PR has merged (e.g., audit reveals a quiet-period bypass, Rule 7 was silently violated, or CR posts findings post-merge that should have been caught earlier):

1. Add a `Violation: <rule-N>` entry to `.sisyphus/PROGRESS.md` under the offending dp-NN cycle, with one-line description of what slipped through.
2. Open a follow-up `dp-NN-<slug>-followup` plan in `.sisyphus/plans/` that addresses any actual code/contract issue surfaced by the late finding (if any). The follow-up plan goes through the full /ralph-loop including the rules that were violated.
3. Do NOT auto-revert the merged commit. Reverts cause more churn than they prevent at our usage scale; the post-approval-change subprotocol (Phase P3) and Phase P4 drift detection are the forward-fix mechanisms for the pre-merge case, and the follow-up plan is the post-merge case.
4. If three or more violations of the SAME rule accumulate within a quarter, treat that as a signal that the rule's enforcement gap is real and open a `dp-process-NN` plan to harden further.

This is intentionally a **soft consequence**. The hard prevention happens in verify.sh BEFORE merge; post-merge handling is observational + tracked, not punitive. Pairs with Phase P3's post-approval-change subprotocol (which catches late findings BEFORE merge when possible) — together they form a forward-fix safety net rather than a revert-and-redo punishment loop.

---
## Phases — execution checklist

### P1 — Pre-PR CLI + integration-branch enforcement
- [ ] Edit `ralph-loop-contract.md`: add Rule 6 to §Hard rules; rewrite §Loop protocol opening; promote §Tooling CLI section from RECOMMENDED to REQUIRED.
- [ ] Edit `ralph-loop-verify.sh`: add default-branch detection block; add `--allow-direct-default` flag with `--reason` requirement; wire to waivers audit log.
- [ ] Self-test: run verify.sh against a current open PR (or dp-process-03's own PR) with feature branch base — should pass. Then simulate by passing default branch base — should fail with rule-6 message.
- [ ] Self-test: run with `--allow-direct-default --reason "test bootstrap"` — should pass and log to waivers file.

### P2 — Mandatory comment-then-resolve
- [ ] Edit `ralph-loop-contract.md`: add Rule 7 to §Hard rules; rewrite "If you agree" subsection in §Evaluating findings with template comment text; tighten reject-side wording.
- [ ] Edit `ralph-loop-verify.sh`: add GraphQL-based thread reply check; verify it handles both `coderabbitai` and `coderabbitai[bot]` author logins.
- [ ] Self-test against PR #18 (extension): expect FAIL on Rule 7 (silent resolution pattern).
- [ ] Self-test against PR #15 (extension): expect PASS on Rule 7 (last PR with explicit accept comments).
- [ ] If self-test results don't match expectations, debug the GraphQL query before merging.

### P3 — Quiet period after last CR activity
- [ ] Edit `ralph-loop-contract.md`: add Rule 8 to §Hard rules; insert quiet-period step in §Loop protocol.
- [ ] Edit `ralph-loop-verify.sh`: add quiet-period check; add `--quiet-period-seconds`, `QUIET_PERIOD_SECONDS` env, `--skip-quiet-period --reason` flag.
- [ ] Sample 5 recent CR ack comments to confirm `startswith("Actions performed:")` filter is correct. Adjust filter if needed.
- [ ] Self-test: run against dp-process-03's own PR with `QUIET_PERIOD_SECONDS=60` shortly after a CR comment — should fail. Wait 60s, re-run — should pass.
- [ ] Self-test with `--dry-run` against PR #20 — should report Rule 8 status without exiting non-zero.

### Final integration
- [ ] Add `--dry-run` flag to verify.sh: bypasses non-zero exit but still prints all rule pass/fail outcomes.
- [ ] Update `dp-process-02-ralph-loop-v2-enhancements.md` Status section: append "**Superseded in part by dp-process-03**" with brief one-line note for each amended rule.
- [ ] Open PR per repo (outer Paymenter holds the actual files). Title: `chore(process): harden CR loop — mandatory pre-PR CLI + thread comments + quiet period (dp-process-03)`.
- [ ] Run the new verify.sh against the dp-process-03 PR itself (dogfood; should pass cleanly).
- [ ] Merge per the (newly-tightened) gate.

---

## Acceptance

- All three rules (6, 7, 8) ship in the contract.
- `ralph-loop-verify.sh` enforces all three; each has a documented escape flag with `--reason` audit-logging.
- Self-test against ≥2 historical PRs confirms Rule 7 would have caught past silent-resolution incidents.
- The first dp-20 PR after merge follows the new workflow end-to-end with no contract violations.
- `dp-process-02-ralph-loop-v2-enhancements.md` cross-references this plan in its Status section.
- Phase P4: extension repo has `.sisyphus/templates/` with synced copies; `--check-sync` mode passes after copy; SYNC.md present.
- Post-approval-change subprotocol mandates `@coderabbitai full review` after late CR comments AND is documented in §Loop protocol.
- Nit-handling carve-out documented (silent accept OK for nits; disagree MUST comment on any finding including nits).
- §Post-merge violation handling section drafted with PROGRESS.md note + follow-up dp-NN flow (no auto-revert).

---

## Commit

Two PRs total: (1) outer Paymenter PR with P1+P2+P3 contract+verify.sh changes (may split into 3 phase-PRs per the §Proposed Design heuristic — default is single PR); (2) extension-repo PR for P4 cross-repo sync, opened from inside `extensions/Others/DynamicPterodactyl/` (nested git, per outer CLAUDE.md FAIL rule).

```
chore(process): harden CR loop with 3 new rules (dp-process-03)

- Rule 6: mandatory pre-PR cr review + integration-branch base
- Rule 7: every CR thread requires Jordanmuss99 reply before resolve
- Rule 8: 10-min quiet period after last CR activity before merge
- Rule 8 prose: "do not rush" rationale paragraph
- Rule 8 supplement: post-approval-change subprotocol (`@coderabbitai full review` on changes after APPROVED)
- verify.sh: `--wait` flag (auto-poll-and-wait mode)
- Verbatim 8-step driver checklist embedded in §Loop protocol
- Nit-handling carve-out (silent accept OK for nits; disagree MUST comment)

Each rule has a verify.sh check, an audited escape-hatch flag, and a forward-fix mechanism (post-approval-change subprotocol pre-merge; §Post-merge violation handling post-merge).
Closes the bypass patterns observed in dp-13 → dp-19.
```

---

## Delegation

`task(category="deep", load_skills=["code-review"], run_in_background=true, ...)`. Touches a single concern (governance contract + script) but has 3 phases, GraphQL queries, self-tests against historical data, and contract-prose changes. Deep category fits.

The subagent must:

**Pre-flight reads:**
1. Read this plan end-to-end.
2. Read `.sisyphus/templates/ralph-loop-contract.md` end-to-end.
3. Read `.sisyphus/templates/ralph-loop-verify.sh` end-to-end (the existing rule-1 through rule-5 implementations are the template for rule-6/7/8).
4. Sample CR's actual `author.login` value via `gh api graphql` against any recent CR-reviewed PR — confirm whether it's `coderabbitai` or `coderabbitai[bot]` (or both) so the check queries are correct.
5. Sample 3-5 recent CR auto-ack comments to confirm the `startswith("Actions performed:")` filter for Rule 8.

**Implementation discipline:**
6. Each phase ships as ONE commit — contract diff + script diff + self-test artifacts in the same commit.
7. Self-test outputs (the actual verify.sh runs against PR #15/#18/#19/#20) go in commit messages or in `.sisyphus/notepads/dp-process-03/self-tests.md`.
8. Do NOT use `--allow-direct-default` on the dp-process-03 PR itself. The dp-process-03 PR demonstrates the new workflow.

**Cross-repo discipline:**
9. P1+P2+P3 changes commit from `/var/www/paymenter/` only (`.sisyphus/templates/` lives there). Do NOT touch any path under `extensions/Others/DynamicPterodactyl/` from the outer working tree — that path is a nested git repo and outer-tree commits there will fail the CLAUDE.md FAIL rule.
10. P4 REQUIRES extension-repo changes (contradicts the original draft of this list, which predated P4): seed `.sisyphus/templates/ralph-loop-contract.md`, `ralph-loop-verify.sh`, and `SYNC.md` from the outer canonical, then open a dedicated extension-repo PR. All P4 commits MUST run from inside the nested repo (`cd extensions/Others/DynamicPterodactyl && git commit && gh pr create --base dynamic-slider`), per the recipe in §Phase P4 → CLAUDE.md interaction. Outer-tree commits to extension paths violate the FAIL rule and will be blocked by pre-commit / CR review.

---

## Status

- [x] Plan written (you are here)
- [x] Pre-flight reads complete (subagent)
- [x] CR `author.login` sampling complete; query strings confirmed
- [x] CR ack-message filter confirmed against ≥3 recent samples
- [x] P1: Rule 6 in contract + default-branch check in verify.sh + `--allow-direct-default` flag
- [x] P1: verbatim 8-step driver checklist embedded in §Loop protocol
- [x] P2: Rule 7 in contract + thread-reply GraphQL check in verify.sh
- [x] P2: nit-handling carve-out (silent-accept OK for nits / disagree MUST comment) added to §Evaluating findings
- [x] P3: Rule 8 in contract + quiet-period check in verify.sh + `--quiet-period-seconds` flag
- [x] P3: "do not rush" rationale paragraph appended to Rule 8 prose
- [x] P3: `--wait` flag (auto-poll-and-wait mode) added to verify.sh quiet-period check
- [x] P3: post-approval-change subprotocol (`@coderabbitai full review` on changes after APPROVED) added to §Loop protocol
- [x] `--dry-run` flag added to verify.sh
- [ ] P4: extension repo `.sisyphus/templates/` created with contract + verify.sh copies + SYNC.md (commit from inside nested repo per CLAUDE.md FAIL rule)
- [ ] P4: `--check-sync` mode added to verify.sh (drift detection between outer canonical and extension copy)
- [ ] §Post-merge violation handling section present (PROGRESS.md note + follow-up dp-NN flow, no auto-revert)
- [x] Self-test: PR #18 — confirm Rule 7 FAIL (retroactive)
- [x] Self-test: PR #15 — confirm Rule 7 PASS (retroactive)
- [x] Self-test: PR #20 — `--dry-run` summary captured
- [x] dp-process-02 Status section cross-references this plan
- [x] PR opened against integration branch in outer Paymenter
- [ ] CR review cycle complete on dp-process-03 PR (dogfood new rules)
- [ ] PR merged per new gate
- [ ] First dp-20 PR after merge confirms the new workflow works end-to-end

---

## References

- Predecessor: `.sisyphus/plans/dp-process-02-ralph-loop-v2-enhancements.md`
- Original incident: `.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md`
- Active contract: `.sisyphus/templates/ralph-loop-contract.md`
- Active script: `.sisyphus/templates/ralph-loop-verify.sh`
- CR docs — learnings model: https://docs.coderabbit.ai/knowledge-base/learnings
- CR docs — review threads / commands: https://docs.coderabbit.ai/guides/commands
- GitHub GraphQL — pull request review threads: https://docs.github.com/en/graphql/reference/objects#pullrequestreviewthread
- Last PR with explicit accept-side comments (regression baseline): https://github.com/Jordanmuss99/dynamic-pterodactyl/pull/15
- dp-19 plan (the bypass that triggered Rule 6): `.sisyphus/plans/dp-19-wire-slider-to-reservation-api.md`
