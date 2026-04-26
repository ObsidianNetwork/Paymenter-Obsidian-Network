# /ralph-loop Contract v2 — Canonical Template (CodeRabbit Pro)

**Purpose**: mandatory CodeRabbit review workflow for every dp-NN (and any) PR against `dynamic-slider`. This file is the single source of truth. dp-NN plans MUST reference it by path instead of re-stating the contract.

**Origin**: initially hardened after the 2026-04-24 incident (wrong PR author, zero CR reviews merged). Redesigned as v2 on 2026-04-24 after live research against `docs.coderabbit.ai` revealed that CR publishes structured signals (`commit_status`, `request_changes_workflow`, `fail_commit_status`, `auto_review.base_branches`) that make timestamp polling and silence-is-clean unnecessary. See `.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md` and `.sisyphus/plans/dp-process-01-ralph-loop-v2.md`.

---

## Plan-tier reality (READ THIS FIRST)

| Capability | Jordanmuss99 (Pro) | ImStillBlue (Free) |
|---|---|---|
| Auto-review on PR open (default branch) | YES | YES |
| Auto-review on non-default base branches | YES (via `.coderabbit.yaml` `base_branches`) | NO |
| Auto-review on every push | YES | YES |
| `@coderabbitai review` on-demand | YES | NO |
| Conversational chat / thread replies | YES | NO |
| Formal APPROVED / CHANGES_REQUESTED reviews | YES | NO |

**Rate limits (Pro plan, per developer, refilling bucket):**
- PR reviews: 5/hour (1 review every 12 min)
- Chat: 50/hour
- Exceeding the bucket pauses new reviews until it refills.

PRs MUST be opened under `Jordanmuss99` to get Pro review. See "PR author identity" below.

---

## PR author identity (CRITICAL)

CodeRabbit checks entitlement from the GitHub login on the PR itself — the account that ran `gh pr create`. Subscription tier is evaluated against that login, not the commit author.

**Evidence**: PRs #10 and #11 (2026-04-24) were opened under `ImStillBlue` (Free) and received zero reviews. PR #9 opened under `Jordanmuss99` (Pro) received 3 auto-reviews. Same commit authors, same repo.

For this project:
- Pro-entitled login: `Jordanmuss99`
- Commit author email: `164892154+Jordanmuss99@users.noreply.github.com` (noreply form — avoids GH007 push rejection)

### Required sequence BEFORE `gh pr create`

```bash
gh auth switch -u Jordanmuss99
gh api /user --jq '{login, id}'
# Must print: {"login":"Jordanmuss99","id":164892154}
# If not, STOP. Do not run gh pr create.
gh pr create --base <integration-branch> --title "..." --fill
```

PR author is immutable on GitHub. Opening under the wrong account cannot be fixed without closing and re-opening the PR.

### Git commit author (secondary but required)

```bash
git config user.name "Jordanmuss99"
git config user.email "164892154+Jordanmuss99@users.noreply.github.com"
# Verify after every commit:
git log -1 --format='%an <%ae>'
```

---

## Repository prerequisites (MUST exist before first dp-NN PR)

Every repo using /ralph-loop MUST have `.coderabbit.yaml` committed to its **default branch**. This config is what enables:
- Auto-review on integration branches (`base_branches`)
- Continuous review across many pushes (`auto_pause_after_reviewed_commits: 0`)
- Fail-loud when CR cannot review (`fail_commit_status: true`)
- Formal APPROVED / CHANGES_REQUESTED signal (`request_changes_workflow: true`)

**Canonical configs** (do not re-state values here; edit the files directly):
- `ObsidianNetwork/Paymenter-Obsidian-Network`: `.coderabbit.yaml` on `master` (shipped PR #5, 2026-04-24)
- `Jordanmuss99/dynamic-pterodactyl`: `.coderabbit.yaml` on `dynamic-slider` (shipped PR #13, 2026-04-24)

If a new repo enters the workflow, add `.coderabbit.yaml` on its default branch before opening any dp-NN PRs. Use the existing config files as templates.

**Verification (run once per repo):**
```bash
gh api repos/<owner>/<repo>/contents/.coderabbit.yaml --jq .name
# Must print: .coderabbit.yaml
```

---

## Hard rules (non-negotiable, mechanically verified)

All six rules are checked by `ralph-loop-verify.sh` before every merge. "Mechanically verified" means the script exits non-zero if the rule fails — prose reasoning alone does not substitute.

0. **PR author = `Jordanmuss99`.** Immutable on GitHub. Wrong author → close, switch account, reopen.

1. **Base branch is an integration branch, not a default.** For dp-NN PRs, base must NOT be `master`, `main`, or `develop`. Use `--expected-base '^master$'` for intentional infra/config PRs that target master.

2. **All commit author emails = `164892154+Jordanmuss99@users.noreply.github.com`.** Script checks every commit on the PR via GitHub REST API.

3. **CodeRabbit status check `CodeRabbit` = `pass`.** CR publishes this status check (pending while reviewing, pass when done). This replaces all prior timestamp-comparison logic and silence-is-clean logic. If the check is missing, CR has not reviewed yet. Wait.

4. **`mergeStateStatus == CLEAN`.** Covers: mergeable + all required CI checks SUCCESS + no blocking reviews.

5. **Zero unresolved review threads.** Every CR thread must be closed via reply + resolve. Silent resolution (clicking "Resolve" with no reply) is a contract violation.

---

## Using `@coderabbitai` commands

CR commands are invoked by posting exact-match comment text on a PR. Only `Jordanmuss99`-authored PRs have Pro command access.

| Command | When to use | Precondition | Do NOT use when |
|---|---|---|---|
| `@coderabbitai review` | Request re-review when no new commit is needed (e.g., after reasoning-only thread resolution) | CR status is NOT already `pending` (review in progress) | Right after a push — auto-review handles it |
| `@coderabbitai full review` | Want fresh whole-PR insights after many incremental reviews | — | Routine single-commit flow |
| `@coderabbitai pause` | Making several rapid WIP commits and want to suppress review noise | — | Normal /ralph-loop flow |
| `@coderabbitai resume` | Restart auto-review after a prior `pause` | A `@coderabbitai pause` was explicitly posted on THIS PR | As a retry mechanism — it is not a synonym for `review` |
| `@coderabbitai <question>` | Discuss a finding, ask for clarification, or push back before closing a thread | — | Trivial acks that a plain comment covers |
| `@coderabbitai resolve` | Mark all CR threads resolved in one shot AFTER every thread has a Jordanmuss99 reply | Every thread has a reply — do NOT use as a shortcut to skip replies | Before you have replied to each thread |
| `@coderabbitai autofix` | **NEVER** — bypasses critical-evaluation rule | — | Always |
| `@coderabbitai ignore` | Emergency hotfix that cannot wait for review | User explicitly approves escape | Routine flow |

### Mention cooldown (120 seconds — hard rule)

Do NOT post any `@coderabbitai` mention within 120 seconds of a prior one on the same PR. Before posting, check:
```bash
gh pr view <N> --json comments \
  --jq '[.comments[] | select(.author.login=="Jordanmuss99" and (.body | startswith("@coderabbitai")))] | max_by(.createdAt) | .createdAt'
```
If the result is less than 120 seconds ago, wait. Multiple rapid mentions re-arm CR's internal state machine, burn rate-limit budget (5 reviews/hr), and cause the agent to mistake silence for approval.

### Post-mention ack check

After posting `@coderabbitai review`, wait up to 60 seconds. CR should reply with "Actions performed: Review triggered." If no ack appears within 60 seconds, do NOT post again — CR may be processing. Check the status check for `pending`. Only if the status check is absent AND no ack after 5 minutes should you consider posting a second mention.

---

## Evaluating findings

CodeRabbit is a tool — it can be wrong. Every finding MUST be evaluated before action. Never blindly apply a suggestion.

### Before acting

1. **Does the suggestion match the code's actual intent?** Read the function, surrounding code, and related tests.
2. **Does it align with existing repo patterns?** A "fix" that introduces a convention the repo doesn't use is a rejection.
3. **Is it in scope for this PR?** Out-of-scope work goes to a dp-NN plan, not this PR.
4. **Is it a real bug or a false positive?** Trace the code yourself before applying.

### If you agree → fix it

Push a follow-up commit. Auto-review fires (CR config has `auto_incremental_review: true`). Wait for the `CodeRabbit` status check to return to `pass`.

### If you disagree or it is out of scope → reject with reasoning

Reply on the thread with all three:
1. What CR claimed (one sentence).
2. Why it is wrong / out-of-scope / already addressed (concrete reason, not "I disagree").
3. What the intended design is, or where it is deferred (pointer to code / dp-NN plan).

Then resolve the thread. Both plain GitHub comments and `@coderabbitai <rationale>` replies are acceptable. Use `@coderabbitai` when the point is genuinely arguable and you want CR to respond before closing.

Document the rejection in the next commit message or a plan-level note.

**Never close a thread without a reply. Never ignore a finding silently.**

---

## Loop protocol (status-check-driven)

This protocol replaces all prior timer-based / timestamp-comparison logic.

```
push commit
  └─ wait up to 60s for 'CodeRabbit' status = pending
     (if no status after 60s and no .coderabbit.yaml on default branch → escalate)
     │
     └─ poll every 30s until status = pass or fail
        │
        ├─ fail → CR cannot review (wrong PR author, repo not connected, etc.) → ESCALATE
        │
        └─ pass → read CR review comments
           │
           ├─ findings present (unresolved threads > 0)?
           │   ├─ agreed finding → push fix commit → back to top
           │   └─ disagree / out-of-scope → reply with 3-part rationale → resolve thread
           │       └─ all threads resolved? → run ralph-loop-verify.sh
           │
           └─ no findings (threads = 0) → run ralph-loop-verify.sh
              │
              └─ PASS → gh pr merge --squash --delete-branch
```

### `@coderabbitai review` is now the exception

With `.coderabbit.yaml` in place, auto-review fires on every push. You do NOT need to post `@coderabbitai review` after a push. Reserve it for:
- Reasoning-only thread resolution (no new commit, want CR to re-scan)
- CR status stuck `pending` > 15 minutes (potential CR hang)

If you do post it: observe the auto-ack, then wait. Do not post a second mention unless CR posts a non-ack substantive comment that needs a response.

### After merge

```bash
# In the integration-branch repo (ObsidianNetwork or dynamic-pterodactyl):
git checkout <integration-branch>   # e.g. dynamic-slider/1.4.7
git pull --ff-only

# Get squash SHA:
gh pr view <N> --json mergeCommit --jq '.mergeCommit.oid' | cut -c1-8

# Append PROGRESS.md row (extension repo) or FORK-NOTES.md entry (parent repo).
# Archive boulder: mv .sisyphus/boulder.json .sisyphus/completed/<plan-name>.boulder.json
```

---

## Mechanical gate (MANDATORY before `gh pr merge`)

```bash
bash .sisyphus/templates/ralph-loop-verify.sh <PR_NUMBER> \
  --repo <owner/name> \
  --expected-base '<regex>'   # e.g. '^dynamic-slider' for dp-NN PRs
```

The script exits non-zero if any hard rule fails. **Do not merge if it exits non-zero. Do not bypass the script.**

Options:
- `--expected-base '^master$'` — for config/infra PRs targeting master
- `--allow-actionable --reason "..."` — bypass CR clean-verdict check with logged rationale (last resort; writes audit entry to `.sisyphus/notepads/ralph-loop-waivers.jsonl`)

If the script is unavailable, run the inline equivalent:
```bash
pr=<PR_NUMBER>; repo=<owner/name>
gh pr view $pr --repo $repo --json author --jq '.author.login'  # must be Jordanmuss99
gh pr checks $pr --repo $repo | grep -E '^CodeRabbit\b'          # must show pass
gh pr view $pr --repo $repo --json mergeStateStatus --jq '.mergeStateStatus'  # must be CLEAN
owner=${repo%%/*}; rname=${repo##*/}
gh api graphql -f query='query($o:String!,$r:String!,$p:Int!){repository(owner:$o,name:$r){pullRequest(number:$p){reviewThreads(first:100){nodes{isResolved}}}}}' \
  -F o="$owner" -F r="$rname" -F p="$pr" \
  --jq '[.data.repository.pullRequest.reviewThreads.nodes[] | select(.isResolved==false)] | length'  # must be 0
```

---

## Escalation

Escalate to the user (do NOT merge) when:
- CR status check `CodeRabbit` stays `fail` — CR refused to review (wrong PR author, repo disconnected, etc.).
- CR status check absent AND `@coderabbitai review` mention produced no ack within 5 minutes — integration broken.
- CR status stuck `pending` > 15 minutes after a push or manual mention — CR hang; wait another 5 min then escalate.
- A CR finding requires changes outside the PR's declared scope AND there is no dp-NN plan to defer to.
- `@coderabbitai` chat produces a counter-argument you cannot resolve without user input.
- PR author ≠ `Jordanmuss99` — close PR, switch account, reopen.

---

## The driver's pre-flight and post-merge verification

Any agent or process running /ralph-loop is "the driver". The driver MUST:

**Pre-flight (before spawning subagents or opening any PR):**
```bash
gh auth switch -u Jordanmuss99
login=$(gh api /user --jq .login)
[ "$login" = "Jordanmuss99" ] || { echo "ABORT: gh active user is $login"; exit 1; }
```

**Post-merge verification (independent of subagent claims):**
```bash
gh pr view <N> --repo <owner/name> --json mergedAt,author,reviews \
  --jq '{mergedAt, author: .author.login, cr_reviews: ([.reviews[] | select(.author.login=="coderabbitai")] | length)}'
```
Treat as CONTRACT VIOLATION (do not mark work complete) when ANY of:
- `author ≠ Jordanmuss99`
- `mergedAt` absent (not merged)
- `cr_reviews < 1` AND the `CodeRabbit` status check was absent at merge time (no review occurred)

---

## Related files

- `.sisyphus/templates/ralph-loop-verify.sh` — executable gate (v2)
- `.sisyphus/plans/dp-process-01-ralph-loop-v2.md` — this refactor's plan + Phase 2 live validation results
- `.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md` — root-cause record
- `.sisyphus/notepads/ralph-loop-waivers.jsonl` — audit log for `--allow-actionable` bypass uses
- `ObsidianNetwork/Paymenter-Obsidian-Network:.coderabbit.yaml` — repo-level CR config (on `master`)
- `Jordanmuss99/dynamic-pterodactyl:.coderabbit.yaml` — repo-level CR config (on `dynamic-slider`)
- `extensions/Others/DynamicPterodactyl/CLAUDE.md` — references this file at "CodeRabbit Review Mandate"
- `extensions/Others/DynamicPterodactyl/DECISIONS.md` — process decision entries
