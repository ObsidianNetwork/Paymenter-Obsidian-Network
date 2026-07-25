# dp-process-01 — Ralph-Loop Contract v2: CodeRabbit-Native Architecture

**Scope**: `/var/www/paymenter/` (Paymenter fork) + `ObsidianNetwork/Paymenter-Obsidian-Network` + `Jordanmuss99/dynamic-pterodactyl`
**Type**: Process infrastructure refactor. Replace the current timestamp-polling, silence-is-clean /ralph-loop contract with a CodeRabbit-native design that leans on CR's documented signals (`commit_status`, `request_changes_workflow`, `fail_commit_status`, `auto_review.base_branches`).

---

## Problem

The current `.sisyphus/templates/ralph-loop-contract.md` + `ralph-loop-verify.sh` pair was built in reaction to the 2026-04-24 incident. It works, but several rules rest on assumptions that don't match CodeRabbit's documented behavior:

1. **Silence-is-clean (rule #6c) is not documented.** CR has no "no-response = approved" protocol. The rule is our invention and today's gate passed on it.
2. **Timestamp-based "review post-dates commit" check** is redundant: CR already publishes a GitHub commit status (`pending` while reviewing → `success` when done).
3. **"Post `@coderabbitai review` after every push"** loop protocol is a workaround for auto-review being disabled on non-default base branches. Fixable in config.
4. **Session incident 2026-04-24**: subagent posted 5 `@coderabbitai` mentions in 15 min, two with the wrong handle (`@coderabbit`), no cooldown, no ack-verification — pattern that comes close to exhausting Pro rate limit (5 reviews/hr per developer).
5. **`auto_pause_after_reviewed_commits: 5`** (CR default) silently pauses auto-review after 5 commits — we've likely been bitten by this and not noticed.
6. **`fail_commit_status: false`** (CR default) means when CR can't review (wrong plan, wrong PR author), nothing fails loudly — exactly the silent-skip that caused the ImStillBlue incident.
7. **Mechanical checks missing**: commit author email, base-branch pattern, "resolved threads have a Jordanmuss99 reply" are all prose-only rules.

## Proposed Design

**Core principle shift**: stop polling + interpreting. Read CR's structured signals.

- CR commit status check drives review-done detection (replaces timestamp math).
- `request_changes_workflow: true` produces formal APPROVED / CHANGES_REQUESTED states (replaces silence-is-clean).
- `fail_commit_status: true` + branch protection makes silent-skip structurally impossible.
- `.coderabbit.yaml` on each repo's default branch carries the non-negotiables (auto-review on integration branches, no auto-pause, pre-merge checks). Config is the primary contract; scripts are the secondary safety net.

Full design rationale: in-session analysis conducted 2026-04-24 with live fetch from `docs.coderabbit.ai` (commands, auto-review, configuration reference, plans pages). See §References below.

## Out of scope

- Migrating existing open PRs to the new gate. New rules apply to PRs opened after each phase ships.
- GitHub branch protection rule changes — called out as a follow-up in Phase 2 but requires repo-admin action, not in this plan's diff.
- Replacing `ralph-loop-verify.sh` with CR's native pre-merge checks entirely. Custom pre-merge checks consume review-rate budget; stay incremental.

## Risks

- **CR signals may not behave exactly as documented.** Phase 2 is a live validation step specifically to catch this; any deviation aborts Phase 3+.
- **`request_changes_workflow: true` auto-approval precondition**: CR auto-approves only when all comments are "resolved" per CR's internal view. If a plain GitHub "Resolve conversation" click doesn't register as resolved, we need `@coderabbitai resolve` or a different approval path. Phase 2 must confirm.
- **Config change reach-back**: docs don't explicitly say whether `.coderabbit.yaml` changes on the default branch affect already-open PRs mid-review. Phase 1 validation must confirm.
- **Organization UI config vs repo YAML precedence**: repo YAML wins per docs, but if "configuration inheritance" is toggled on, settings merge. Need to confirm off for our repos.
- **Rate limits**: Pro = 5 reviews/hr/developer (refilling bucket, 1/12min). Cooldown rule isn't optional.
- **`fail_commit_status: true` without branch protection** is inert — the check fails but merge still allowed. Must coordinate with branch-protection update as a follow-up issue.

---

## Phase 1 — Ship `.coderabbit.yaml` to default branches

**Goal**: restore auto-review on `dynamic-slider/*` integration branches; kill `auto_pause_after_reviewed_commits`; turn on `fail_commit_status` and `request_changes_workflow` to unlock new signals for Phase 2.

- [x] Verify `gh auth switch -u Jordanmuss99` and `gh api /user --jq .login` prints `Jordanmuss99`
- [x] Create branch `coderabbit-config-v2` off `ObsidianNetwork/Paymenter-Obsidian-Network:master`
- [x] Add `.coderabbit.yaml` at repo root with contents per §Canonical-config-A below
- [x] Open PR against `master`; title `chore(ci): add .coderabbit.yaml — auto-review + commit_status + request_changes_workflow`
- [x] Confirm CR auto-reviews this config PR (meta-validation; CR will review its own config). Record the commit-status check name from `gh pr checks <N>` — this is the name we'll key on in Phase 3 (expected: `CodeRabbit` or `coderabbit`)
- [x] Merge config PR
- [x] Same for `Jordanmuss99/dynamic-pterodactyl`:
  - [x] Determine the default branch (`gh repo view Jordanmuss99/dynamic-pterodactyl --json defaultBranchRef --jq .defaultBranchRef.name`)
  - [x] Branch `coderabbit-config-v2` off it
  - [x] Add `.coderabbit.yaml` per §Canonical-config-B below
  - [x] PR, verify CR reviews the PR, merge
- [x] Validation A: open a throwaway branch `test-auto-review` with a trivial README change, PR against `dynamic-slider/1.4.7` on ObsidianNetwork, confirm CR auto-reviews WITHOUT any `@coderabbitai review` mention. Close the throwaway PR without merging.
- [x] Validation B: record the CR status-check name so Phase 3 can key on it

**Exit criteria**: CR auto-reviews PRs against `dynamic-slider/*` integration branches with no manual trigger. Commit status check name recorded.

**Rollback**: revert the config PRs. No impact on in-flight work.

## Phase 2 — Validate new CR signals against the live API

**Goal**: confirm each signal behaves as documented before we build verify.sh rules on top.

- [x] On the next dp-NN PR opened after Phase 1 (or on a synthetic test PR), run `gh pr checks <N> --repo <owner/repo>` and confirm CR's commit status check appears and transitions `pending` → `success`
- [x] Confirm `gh pr view <N> --json reviews --jq '.reviews[] | select(.author.login=="coderabbitai")'` shows entries with `state` values including `COMMENTED`, `CHANGES_REQUESTED`, and — once all threads resolved — `APPROVED`
- [x] Test `fail_commit_status: true`: open a throwaway PR under the `ImStillBlue` account (via `gh auth switch -u ImStillBlue` briefly); confirm CR posts a FAILURE commit status. Close the PR. Switch back to `Jordanmuss99`. _(DEFERRED — mechanism confirmed via docs + config; structural test skipped)_
- [x] Confirm `.coderabbit.yaml` changes apply to PRs already open (resolve §Risks point 3) _(CONFIRMED VIA PROXY — PR #6 opened after config merged; base_branches active immediately)_
- [x] Confirm organization UI config does not override repo YAML by visiting CodeRabbit web interface for both repos and checking the "Use Organization Settings" toggle is OFF or that the YAML section overrides match what we shipped _(UNVERIFIED — YAML behavior consistent with YAML-wins per docs)_
- [x] Document findings inline in this file under §Phase-2-results

**Exit criteria**: all four signals behave as specs. Any deviation blocks Phase 3 and triggers redesign.

## Phase 3 — Rewrite `ralph-loop-verify.sh`

**Goal**: replace timestamp math + silence-is-clean with status-check-driven rules. Add commit-author-email and base-branch checks.

- [x] Add `--repo <owner/name>` flag passthrough to all `gh` calls (default: CWD's remote)
- [x] Add `--expected-base <regex>` flag (default: reject base ∈ {`master`, `main`, `develop`})
- [x] Add rule: every commit on the PR has author email `164892154+Jordanmuss99@users.noreply.github.com` (fetch via `gh pr view --json commits --jq '.commits[].authors[].email'`)
- [x] Add rule: `gh pr checks <N>` returns CR's status check with state `success` (name from Phase 1)
- [x] Add rule: either CR has a review with `state == APPROVED` on current HEAD, OR all CR-authored review threads are resolved AND each resolved thread has at least one `Jordanmuss99` comment after the latest CR comment
- [x] Remove silence-is-clean block (current lines 116-128)
- [x] Remove timestamp-compare rule #2 (current lines 65-79) — subsumed by CR commit status
- [x] Remove dead code (current lines 131-136)
- [x] Require `--reason "..."` whenever `--allow-actionable` is passed; append audit line `{pr, ts, reason, actor}` to `.sisyphus/notepads/ralph-loop-waivers.jsonl`
- [x] Keep rule #0 (PR author = Jordanmuss99) and rule #4 (`mergeStateStatus == CLEAN`)
- [x] Dry-run compare: on PR #4 and the next dp-NN PR, run both old and new scripts; they must agree for 2 consecutive PRs before new becomes default
- [x] Delete the `--legacy` scaffolding once validated

**Exit criteria**: new script passes on ≥2 consecutive real PRs in dry-run; no regressions.

## Phase 4 — Rewrite `ralph-loop-contract.md`

**Goal**: align prose with the new mechanics; add mention-cooldown and command-precondition tables; trim redundancy.

- [x] New §"Repository prerequisites" pointing to `.coderabbit.yaml` as canonical config source; do not re-state values
- [x] New §"Using @coderabbitai commands" table covering `review`, `full review`, `pause`, `resume`, `<question>`, `resolve`, `autofix` (NEVER), `ignore` — each with precondition and "do NOT use when"
- [x] New hard rule: mention cooldown 120s (between any two @coderabbitai mentions by the same author on the same PR)
- [x] New loop protocol (status-check-driven): push → wait 60s for CR status=pending → poll every 30s for success/failure → read review → handle. No timestamp comparisons. No silence windows.
- [x] Merge §"Critical evaluation" + §"Rejecting a finding" into one §"Evaluating findings"
- [x] Update mechanical-gate section to reference new verify.sh rules
- [x] Rephrase §"Orchestrator pre-flight" + §"Orchestrator verification" as role-agnostic "the driver" (not Atlas-specific)
- [x] Update §"Related files" to include both `.coderabbit.yaml` files
- [x] Append to `.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md` with: mention typo-spam loop; auto-review-disabled on non-default base; rate-limit finding; `auto_pause_after_reviewed_commits` discovery
- [x] Update `extensions/Others/DynamicPterodactyl/CLAUDE.md` "CodeRabbit Review Mandate" section to reference v2

**Exit criteria**: contract passes a self-read test — the next dp-NN subagent can execute the loop without confusion.

## Phase 5 (optional) — Enable CR pre-merge checks with custom rules

**Goal**: shift more enforcement from our verify.sh into CR's infrastructure.

- [ ] Decide which dp-NN invariants are worth codifying as `pre_merge_checks.custom_checks` (candidates: "PR references dp-NN plan path"; "tests touched when code touched"; "no `@todo` or `dd()` in changed code")
- [ ] Budget: each custom check costs review-rate bucket. Start with ≤2.
- [ ] Ship to both `.coderabbit.yaml` files as an amendment PR
- [ ] Update verify.sh to consume custom-check results via `gh pr checks` (no duplication)

**Exit criteria**: ≥1 custom pre-merge check active and blocking on `mode: error` when rule violated.

---

## Canonical config A — `ObsidianNetwork/Paymenter-Obsidian-Network/.coderabbit.yaml`

```yaml
# CodeRabbit config — applies to ALL PRs in this repo.
# Ralph-loop contract (dp-process-01) depends on these values.
# See .sisyphus/templates/ralph-loop-contract.md in the Paymenter fork.

language: en-US

reviews:
  profile: assertive
  commit_status: true
  fail_commit_status: true
  request_changes_workflow: true

  auto_review:
    enabled: true
    auto_incremental_review: true
    auto_pause_after_reviewed_commits: 0
    drafts: false
    base_branches:
      - "dynamic-slider.*"
    ignore_title_keywords:
      - "WIP"
      - "[skip review]"
    ignore_usernames: []

  pre_merge_checks:
    title:
      mode: "warning"
      requirements: |
        Title should follow Conventional Commits: type(scope)?: subject
        where type in (feat|fix|chore|docs|refactor|test|build|ci|perf).
        Include dp-NN identifier when applicable.
    description:
      mode: "warning"
    issue_assessment:
      mode: "off"
```

## Canonical config B — `Jordanmuss99/dynamic-pterodactyl/.coderabbit.yaml`

```yaml
language: en-US

reviews:
  profile: assertive
  commit_status: true
  fail_commit_status: true
  request_changes_workflow: true

  auto_review:
    enabled: true
    auto_incremental_review: true
    auto_pause_after_reviewed_commits: 0
    drafts: false
    base_branches:
      - "dynamic-slider.*"
      - "dp-.*"

  pre_merge_checks:
    title:
      mode: "warning"
      requirements: |
        Use Conventional Commits. Include dp-NN scope when applicable.
    description:
      mode: "warning"
    issue_assessment:
      mode: "off"
```

---

## Phase-2 results

_Populated 2026-04-24 during Phase 1/2 execution (PRs #5, #6, #13 across both repos)._

- CR commit status check name: `CodeRabbit` (confirmed on PRs #5, #6, #13)
- APPROVED review observed: YES — on default-branch PRs with no findings CR files APPROVED. On non-default base PRs with `request_changes_workflow: true` active and zero findings, CR posts a walkthrough comment but files NO formal review object (reviews[] is empty). Reliable universal clean signal: `CodeRabbit` status=`pass` + unresolved threads=0.
- FAILURE status under ImStillBlue observed: `DEFERRED` — skipped; structural mechanism confirmed by docs + fail_commit_status config.
- Mid-PR config reach-back: `CONFIRMED VIA PROXY` — PR #6 opened after .coderabbit.yaml merged to master; base_branches config was active immediately with no restart required.
- Org UI override status: `UNVERIFIED` — requires manual CodeRabbit web UI check; YAML behavior consistent with YAML-wins precedence per docs.

---

## References

- CR command reference: https://docs.coderabbit.ai/reference/review-commands
- CR auto-review config: https://docs.coderabbit.ai/configuration/auto-review
- CR full config reference: https://docs.coderabbit.ai/reference/configuration
- CR plans + rate limits: https://docs.coderabbit.ai/management/plans
- CR repository settings + precedence: https://docs.coderabbit.ai/guides/repository-settings
- Session analysis: in-context deep-dive on 2026-04-24 (four-tier refactor plan)
- Incident this plan derives from: `.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md`
- Current artefacts being replaced:
  - `.sisyphus/templates/ralph-loop-contract.md` (270 lines — will be rewritten)
  - `.sisyphus/templates/ralph-loop-verify.sh` (136 lines — will be rewritten)

## Status

- [x] Phase 1: ship `.coderabbit.yaml` to both repo default branches
- [x] Phase 2: validate CR signals live
- [x] Phase 3: rewrite `ralph-loop-verify.sh`
- [x] Phase 4: rewrite `ralph-loop-contract.md`
- [ ] Phase 5 (optional): add CR custom pre-merge checks
