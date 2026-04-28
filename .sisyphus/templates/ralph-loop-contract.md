# /ralph-loop Contract v2 — Canonical Template (CodeRabbit Pro+)

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

**Rate limits (Pro+ plan, per developer, refilling bucket):**
- PR reviews: 10/hour (1 review every 6 min)
- CLI reviews: 10/hour (separate bucket from PR reviews; see §Tooling)
- Chat: 100/hour
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

All nine rules are checked by `ralph-loop-verify.sh` before every merge. "Mechanically verified" means the script exits non-zero if the rule fails — prose reasoning alone does not substitute.

0. **PR author = `Jordanmuss99`.** Immutable on GitHub. Wrong author → close, switch account, reopen.

1. **Base branch is an integration branch, not a default.** For dp-NN PRs, base must NOT be `master`, `main`, or `develop`. Use `--expected-base '^master$'` for intentional infra/config PRs that target master.

2. **All commit author emails = `164892154+Jordanmuss99@users.noreply.github.com`.** Script checks every commit on the PR via GitHub REST API.

3. **CodeRabbit status check `CodeRabbit` = `pass`.** CR publishes this status check (pending while reviewing, pass when done). This replaces all prior timestamp-comparison logic and silence-is-clean logic. If the check is missing, CR has not reviewed yet. Wait. If the check stays `pending` for ≥ 15 min, the script escalates with the status-page URL and the outage bypass option (see **Outage bypass** below).

4. **`mergeStateStatus == CLEAN`.** Covers: mergeable + all required CI checks SUCCESS + no blocking reviews.

5. **Zero unresolved review threads.** Every review thread must be resolved before merge. Reply requirements live in Rule 7, not here.

6. **Every dp-NN PR targets an integration branch off the default; direct push to a default branch for dp-NN work is a contract violation.** "Default branch" = whatever `gh repo view --json defaultBranchRef --jq .defaultBranchRef.name` returns at the time of work. If the integration branch on a fork IS the default (e.g., `dynamic-slider/1.4.7` on `ObsidianNetwork/Paymenter-Obsidian-Network`), create a feature branch off it (e.g., `dp-NN-<slug>`) and PR back to it.

7. **Every CR-authored review thread has ≥1 Jordanmuss99 reply before resolution.** Silent resolution (clicking "Resolve" without posting a reply comment) is a violation. Applies symmetrically: accept-side replies summarize what was applied; reject-side replies provide the 3-part rationale.

8. **≥10-minute quiet period after most recent CR activity before merge.** "CR activity" = any review submitted or thread / PR comment authored by `coderabbitai` since the last commit on the PR. `Actions performed` auto-ack comments are excluded from activity. (Earlier drafts also listed `CodeRabbit` GitHub-status flips, but they are redundant with comment/review timestamps: the PENDING→SUCCESS flip happens concurrently with CR's final review post per commit, and PENDING itself is gated upstream by the existing pre-quiet-period precondition that requires `CodeRabbit` status to be SUCCESS before the gate is even evaluated.) The verify script computes `now - last_cr_activity_timestamp` and fails if < 600s.

> **Why this rule exists (do not rush)**: CR's APPROVED status is not final. CR may post additional findings within 5-10 minutes after approval, especially when our final commit triggers an incremental review. Merging inside this window has caused us to ship code that CR would have flagged. The rule says "do not rush" — even when the dashboard shows GREEN status and zero unresolved threads, wait the full quiet period before merging. The driver SHOULD use the verify.sh `--wait` flag to make the wait automatic rather than re-running manually.

**Outage bypass**: `--allow-actionable --reason 'CR outage <date> per https://status.coderabbit.ai/<incident-id>'` is permitted ONLY when CR's commit-status has been `pending` for ≥ 15 min AND status.coderabbit.ai shows an active incident. The driver MUST attach the incident URL to the audit log entry (written automatically to `.sisyphus/notepads/ralph-loop-waivers.jsonl`). See zeroclaw-labs/zeroclaw#1792 (2026-02) for the failure mode this rule addresses. The script automatically escalates with the status-page URL and bypass instructions when the 15-min threshold is exceeded.
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
If the result is less than 120 seconds ago, wait. Multiple rapid mentions re-arm CR's internal state machine, burn rate-limit budget (10 reviews/hr shared with CLI reviews), and cause the agent to mistake silence for approval.

### Post-mention ack check

After posting `@coderabbitai review`, wait up to 60 seconds. CR should reply with an auto-ack comment whose body contains `Actions performed` (currently rendered inside an HTML `<summary>` block, e.g. `<summary>✅ Actions performed</summary>`). If no ack appears within 60 seconds, do NOT post again — CR may be processing. Check the status check for `pending`. Only if the status check is absent AND no ack after 5 minutes should you consider posting a second mention.

---

## Evaluating findings

CodeRabbit is a tool — it can be wrong. Every finding MUST be evaluated before action. Never blindly apply a suggestion.

### Before acting

1. **Does the suggestion match the code's actual intent?** Read the function, surrounding code, and related tests.
2. **Does it align with existing repo patterns?** A "fix" that introduces a convention the repo doesn't use is a rejection.
3. **Is it in scope for this PR?** Out-of-scope work goes to a dp-NN plan, not this PR.
4. **Is it a real bug or a false positive?** Trace the code yourself before applying.

### If you agree → fix it AND comment on the thread

1. Push a follow-up commit that addresses the finding.
2. Reply on the CR thread with the template:
   ```
   Applied in <short-sha>: <one-line summary of the change>.
   ```
   Example: `Applied in 9897b06: extracted Alpine fallback x-data so error/status are defined for non-slider products.`
3. Resolve the thread.

Auto-review fires on the new commit; wait for the `CodeRabbit` status check to return to `pass`.

### If you disagree or it is out of scope → reject with reasoning

Reply on the thread with all three:
1. What CR claimed (one sentence).
2. Why it is wrong / out-of-scope / already addressed (concrete reason, not "I disagree").
3. What the intended design is, or where it is deferred (pointer to code / dp-NN plan).

Then resolve the thread. The reject-side reply MUST be posted as a thread reply (not as a top-level PR comment) so it links to the finding it addresses. Both plain GitHub comments and `@coderabbitai <rationale>` replies are acceptable. Use `@coderabbitai` when the point is genuinely arguable and you want CR to respond before closing.

Document the rejection in the next commit message or a plan-level note.

### Nitpick carve-out

**Nitpick (`nitpick`-tagged) findings are partially exempt from the accept-side comment requirement.** If you AGREE with a nitpick: push the fix and resolve the thread; an accept-side comment is OPTIONAL (silent accept is acceptable for nits only). If you DISAGREE with a nitpick: post the standard 3-part reject reply BEFORE resolving — disagreement comments help CR's learnings model reduce future low-value nits, which is the highest-leverage feedback we can give it. Rule 7's mechanical gate excludes nit-tagged threads because accept-vs-reject intent cannot be inferred reliably from thread state alone; the human/driver rule still requires a reply on nit disagreements. Rule 7 still applies in full force to all NON-nit findings on both accept and reject paths.

**Never close a non-nit CR thread without a reply. Never ignore a finding silently.**

---

## Loop protocol (status-check-driven)

This protocol replaces all prior timer-based / timestamp-comparison logic.

**Required pre-PR step (see §Tooling):** run `cr review --plain --type committed --base <integration-branch>` locally before `gh pr create`. A clean result catches blockers before consuming PR-review cycles.

```
implement on feature branch (off integration branch; if the integration branch is the repo default, branch off that default and PR back to it)
  └─ run `cr review --plain --type committed --base <integration-branch>`
     │  (REQUIRED — see §Tooling. Must exit 0 OR all findings addressed/rejected with rationale.)
     │
     ├─ findings present? → fix locally → re-run cr review → repeat
     └─ clean → `gh pr create --base <integration-branch>`
          └─ wait up to 60s for 'CodeRabbit' status = pending
             (if no status after 60s and no .coderabbit.yaml on default branch → escalate)
             │
             └─ poll every 30s until status = pass or fail
                │
                ├─ fail → CR cannot review (wrong PR author, repo disconnected, etc.) → ESCALATE
                │
                └─ pass → read CR review comments
                   │
                   ├─ findings present (unresolved threads > 0)?
                   │   ├─ agreed finding → push fix commit, reply `Applied in <sha>: ...`, resolve thread → back to status poll
                   │   └─ disagree / out-of-scope → reply with 3-part rationale on the thread → resolve thread
                   │
                   └─ all threads resolved + `CodeRabbit` status = pass
                      └─ poll latest CR activity timestamp; if `now - last_cr_activity < 600s`, wait (or use `--wait`) until threshold is satisfied
                         └─ run ralph-loop-verify.sh
                            └─ PASS → gh pr merge --squash --delete-branch
```

Step 1. Finish current plan code changes or fixes for new findings.
Step 2. Run `cr review --plain --type committed` (CodeRabbit CLI) locally on the working branch.
Step 3. CR CLI verification clean?
Step 4. If NO: go back to Step 1 and fix. Else if YES: proceed to Step 5.
Step 5. Create PR (`gh pr create --base <integration-branch>`).
Step 6. Run the CR PR-review loop (auto-review -> reply on threads -> resolve -> re-trigger as needed).
Step 7. CR PR loop reaches APPROVED with all threads resolved AND no further issues come back after approval (Rule 8 quiet period satisfied)?
Step 8. If YES: merge PR to integration branch.

### `@coderabbitai review` is now the exception

With `.coderabbit.yaml` in place, auto-review fires on every push. You do NOT need to post `@coderabbitai review` after a push. Reserve it for:
- Reasoning-only thread resolution (no new commit, want CR to re-scan)
- CR status stuck `pending` > 15 minutes (potential CR hang)

If you do post it: observe the auto-ack, then wait. Do not post a second mention unless CR posts a non-ack substantive comment that needs a response.

### After APPROVED, do not rush

Once CR reaches APPROVED and every thread is resolved, do not merge immediately. Wait for Rule 8's quiet period to elapse first. Preferred driver command:

```bash
bash .sisyphus/templates/ralph-loop-verify.sh <PR_NUMBER> --repo <owner/name> --expected-base '<regex>' --wait
```

### Post-approval-change subprotocol

**If CR posts findings AFTER its APPROVED status and we make code changes in response (pre-merge):**
1. Push the fix commit.
2. Comment `@coderabbitai full review` on the PR to formally re-trigger review (do not rely on auto-review of the new commit alone — auto-review may skip the new commit if CR's last formal status was APPROVED, leaving a stale-approval state).
3. Wait for new APPROVED status + Rule 8 quiet period before merge.

This subprotocol is the forward-fix mechanism for late findings BEFORE merge; pairs with Rule 8 to close the post-approval gap. If late findings appear AFTER merge has already happened, fall through to post-merge violation handling.

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

### Post-merge violation handling

If a contract violation is detected AFTER merge (for example: a silent Rule 7 violation is discovered in audit, a Rule 8 quiet-period bypass slips through, or CR posts late findings after merge that should have been caught earlier):

1. Add a `Violation: <rule-N>` note to `.sisyphus/PROGRESS.md` under the affected dp-NN cycle with a one-line description of what slipped.
2. Open a focused `dp-NN-<slug>-followup` plan in `.sisyphus/plans/` if actual code or contract work is needed.
3. Do **not** auto-revert the merged PR. Forward-fix through a follow-up plan or a scoped repair PR.
4. If the same rule is violated three or more times in one quarter, open a new `dp-process-NN` hardening plan.

The prevention mechanism is the pre-merge verify gate. Post-merge handling is observational + tracked, not punitive.

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
- `--allow-direct-default --reason "..."` — allow a default-branch-targeting PR only for true bootstrap cases; never for routine dp-NN work
- `--quiet-period-seconds <N>` / `QUIET_PERIOD_SECONDS=<N>` — override the Rule 8 wait threshold (default 600)
- `--wait` — wait out Rule 8 automatically instead of hard-failing on an unsatisfied quiet period
- `--skip-quiet-period --reason "..."` — emergency-only Rule 8 bypass with logged rationale
- `--dry-run` — print pass/fail outcomes for every rule without exiting non-zero

If the integration branch on a repo is also the repo default, `--expected-base` will legitimately match that default and no Rule 6 waiver is needed — the required discipline is "feature branch off default, then PR back to it," not a direct push. Use `--allow-direct-default` only for true bootstrap/default-target exceptions outside that normal feature-branch PR flow.

If the script is unavailable, run the inline equivalent (including the Rule 6/7/8 checks):
```bash
pr=<PR_NUMBER>; repo=<owner/name>
expected='<expected-base-regex>'
default=$(gh repo view "$repo" --json defaultBranchRef --jq .defaultBranchRef.name)
base=$(gh pr view $pr --repo $repo --json baseRefName --jq '.baseRefName')
gh pr view $pr --repo $repo --json author --jq '.author.login'  # must be Jordanmuss99
if [ "$base" = "$default" ] && ! printf '%s' "$default" | grep -qE "$expected"; then
  echo "FAIL: base branch '$base' is the repo default and does not match the expected integration-branch regex"
fi
gh pr checks $pr --repo $repo | grep -E '^CodeRabbit\b'          # must show pass
gh pr view $pr --repo $repo --json mergeStateStatus --jq '.mergeStateStatus'  # must be CLEAN
owner=${repo%%/*}; rname=${repo##*/}
gh api graphql -f query='query($o:String!,$r:String!,$p:Int!){repository(owner:$o,name:$r){pullRequest(number:$p){reviewThreads(first:100){nodes{isResolved}}}}}' \
  -F o="$owner" -F r="$rname" -F p="$pr" \
  --jq '[.data.repository.pullRequest.reviewThreads.nodes[] | select(.isResolved==false)] | length'  # must be 0
# then run the Rule 7 thread-reply and Rule 8 quiet-period GraphQL/jq checks from ralph-loop-verify.sh verbatim
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

## Tooling (cr CLI + CR Skills)

### cr CLI — pre-push local review

The `cr` CLI runs a local CodeRabbit review on committed changes without opening a PR. Consumes the same shared 10/hr bucket as PR reviews.

**Install (one-time):**
```bash
npm install -g @coderabbit/cli
```

**Auth (one-time):**

Browser flow (interactive):
```bash
coderabbit auth login
```

Headless flow (requires API key from https://app.coderabbit.ai/settings/api-keys):
```bash
export CODERABBIT_API_KEY="cr-xxx"
coderabbit auth login --api-key "$CODERABBIT_API_KEY"
```
Manual step — the user must obtain the key from the CR dashboard; cannot be automated.

**Verify auth:**
```bash
cr whoami   # must print: Jordanmuss99
```

**Pre-push usage:**
```bash
cr review   # reviews committed diff; exits non-zero on blocking findings
```
**Required for every dp-NN PR before `gh pr create`:**

```bash
cr review --plain --type committed --base <integration-branch>
```

Run after local changes are committed on the feature branch and before `gh pr create`. A clean result does not substitute for the full PR gate (`ralph-loop-verify.sh`), but it is now mandatory because it catches regressions before consuming PR-review cycles.

### CR Skills — agent-invocable review + autofix

Skill files in `~/.agents/skills/` that let this agent invoke CR tooling programmatically without a browser.

**Installed at:**
- `~/.agents/skills/code-review/SKILL.md`
- `~/.agents/skills/autofix/SKILL.md`

**Verify:**
```bash
ls ~/.agents/skills/
```

**Usage policy:**
- `code-review`: invoke for CR-style review of a diff without a PR. Apply the same critical-evaluation standard as §Evaluating findings above.
- `autofix`: invoke to apply CR-suggested fixes. NEVER auto-commit the output — evaluate every change before `git add`. Do NOT use `@coderabbitai autofix` on a PR (see §Using @coderabbitai commands).

## Writing enforceable rules (the FAIL when pattern)

CR auto-detects `**/CLAUDE.md`, `**/AGENTS.md`, `**/.cursorrules`, and several other instruction files. Rules written as `- FAIL when:` are treated as pass/fail criteria rather than prose guidance.

**Pattern:**
```text
## Enforceable rules (CodeRabbit reads these)

- FAIL when: <condition>. Rationale: <why>.
- FAIL when: ...
```

**How to convert prose into a FAIL when rule:**
1. Identify the prohibited action (what must never happen).
2. State it as an observable condition: "a commit touches X", "a file is created at Y", "a migration drops Z without W".
3. Add a one-sentence rationale explaining the invariant.
4. Leave informational context as prose in the surrounding sections.

**Scope**: root files (`/CLAUDE.md`, `/AGENTS.md`) apply everywhere; nested files apply to their subtree only. Encode rules in the deepest file where they belong.

---

---

## Related files

- `.sisyphus/templates/ralph-loop-verify.sh` — executable gate (v3)
- `.sisyphus/plans/dp-process-01-ralph-loop-v2.md` — this refactor's plan + Phase 2 live validation results
- `.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md` — root-cause record
- `.sisyphus/notepads/ralph-loop-waivers.jsonl` — audit log for `--allow-actionable`, `--allow-direct-default`, and `--skip-quiet-period` waiver uses
- `ObsidianNetwork/Paymenter-Obsidian-Network:.coderabbit.yaml` — repo-level CR config (on `master`)
- `Jordanmuss99/dynamic-pterodactyl:.coderabbit.yaml` — repo-level CR config (on `dynamic-slider`)
- `extensions/Others/DynamicPterodactyl/CLAUDE.md` — references this file at "CodeRabbit Review Mandate"
- `extensions/Others/DynamicPterodactyl/DECISIONS.md` — process decision entries
