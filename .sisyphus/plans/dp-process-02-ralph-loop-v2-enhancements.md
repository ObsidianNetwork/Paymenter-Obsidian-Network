# dp-process-02 — Ralph-Loop v2 Enhancements (Consolidated)

**Scope**: `/var/www/paymenter/` (Paymenter fork) + `ObsidianNetwork/Paymenter-Obsidian-Network` + `Jordanmuss99/dynamic-pterodactyl` + dev host (opencode/omo environment)
**Type**: Process + tooling refinement. Builds on `dp-process-01` (the v2 contract that shipped 2026-04-24) with five focused phases. Replaces the original 7-phase plan after a deep research pass, refreshed citations, opencode/omo verification, and an honest overtooling audit.
**Predecessor**: `.sisyphus/plans/dp-process-01-ralph-loop-v2.md` (complete).
**Active harness**: oh-my-openagent (omo) — explains the `LINE#ID` hash-anchored edits, `Sisyphus`/`Prometheus`/`Oracle`/`Hephaestus`/`Librarian`/`Explore` discipline agents, `/init-deep`, built-in MCPs (Exa/context7/grep_app), Comment Checker, Todo Enforcer, and `/ralph-loop` ↔ `/ulw-loop` workflows.

---

## Context — what we're working with (2026-04-24 baseline)

### CodeRabbit plan tier — CONFIRMED Pro+

Pro+ unlocks (relevant to this plan):
- 10 PR reviews/hr (vs Pro 5)
- 10 CLI reviews/hr (separate bucket)
- 100 chat/hr
- 15 MCP server slots (vs Pro 5)
- Up to 20 custom pre-merge checks
- Finishing touches: `simplify`, `unit-test generation`, `merge-conflict resolution`, CodeRabbit Plan (out of scope here, noted for future)

### omo (oh-my-openagent) — already-installed harness

Affects what we *don't* need to build:

| omo capability | Replaces / overlaps with |
|---|---|
| `/init-deep` — auto-generates hierarchical AGENTS.md | bootstraps Phase B for free |
| Hashline (LINE#ID hash-anchored edits) | covers a class of errors `cr` CLI would catch |
| Built-in MCPs (Exa, context7, grep_app) | feed *omo agents*, not CR |
| Discipline Agents (Oracle, Hephaestus) | architecture/debugging review pre-PR |
| Comment Checker | already prevents AI slop in comments |
| Todo Enforcer | already polices "did the agent track work" |
| Hash-anchored edit tool | prevents edit-tool errors that CR CLI would catch |
| Skills system (6 discovery paths: `.opencode/skills/`, `~/.config/opencode/skills/`, `.claude/skills/`, `~/.claude/skills/`, `.agents/skills/`, `~/.agents/skills/`) | CR Skills installable but not necessary at our scale |
| `/ralph-loop` / `/ulw-loop` | the iteration model our contract piggy-backs on |

opencode v1.1.40+ is required for `~/.agents/skills/` global discovery (issue #12741). Project-local `.agents/skills/` has a known bug when CWD == worktree root.

### CodeRabbit credibility — refreshed evidence (2026 Q1-Q2)

I initially undersold CR based on stale + affiliate-marketing sources. Refreshed:

- **Martian Code Review Bench** (independent, ~300k PRs, cited 2026-04-14 Medium): CR has highest F1 score of any AI review tool at **51.2%**, precision 49.2% (~1 in 2 comments leads to a code change).
- **r/cursor independent OSS benchmark**: CR ranks #1 in F1 score. *"Unlike other tools they try to find the most bugs, not just reduce noise."*
- **r/coderabbit "logic leak" example**: CR caught a privacy bug (anonymous user verification flag exposure) that human review missed.
- **CR CLI is at v0.4.1** with active releases — no longer "just launched May 2025"; experienced CR users use it as a standard pre-PR step.
- **Medium 2026-04-14 honest comparison**: CR is "junior reviewer not senior architect" — best deployed as first-pass filter; 50%+ reduction in manual review effort, 80% faster cycles for teams using it that way. Matches our pattern.

### Real outage/error examples motivating Phases A and C

- **zeroclaw-labs/zeroclaw#1792** (Feb 2026): CR was broken; PRs auto-merged including potentially unsafe code. Concrete validation for Phase C (status-page escalation).
- **zeroclaw-labs/zeroclaw#1752** (Feb 2026): `.coderabbit.yaml` parse error caused silent fall-back to defaults, blocked merges. Concrete validation for adding `@coderabbitai configuration` validation step in Phase A.

### Current `.sisyphus/` state — UNTRACKED

Critical finding from this session:
- `/var/www/paymenter/.sisyphus/` exists locally but **0 files tracked** (never `git add`ed).
- All process artifacts (plans, contract, verify.sh, incident logs, decisions) live only on the dev host.
- Single point of failure today.
- Extension repo (`/var/www/paymenter/extensions/Others/DynamicPterodactyl/.sisyphus/`) only contains an empty `run-continuation/`.

This drives Phase A0 (bootstrap commit) — which also replaces the original Phase G (MCP server) entirely, because committed plans + path_instructions give CR the same scope-context with zero infrastructure.

---

## Problem

Ralph-loop v1 (incident-2026-04-24) → v2 (dp-process-01) shipped a working CodeRabbit-native contract. Research surfaced specific gaps:

1. `.sisyphus/` tree (plans, contract, verify.sh, incident logs) is not versioned — single-host failure mode.
2. `.coderabbit.yaml` uses `assertive` profile — community + benchmarks favor `chill` for default; `assertive` belongs on release branches only.
3. Empty `tone_instructions` — 250-char field that can teach CR our scope-respecting reviewer style and cut rejection-reasoning rounds.
4. Empty `path_instructions` — single highest-leverage CR config knob per docs and Martian benchmark; unused.
5. Empty path_instructions for `.sisyphus/**` paths even though those will exist after A0.
6. CR config errors silently fall back to defaults (zeroclaw#1752) — needs `@coderabbitai configuration` validation step.
7. Real CR outages produce stuck `pending` status (zeroclaw#1792) — verify.sh fails generically; should escalate to status.coderabbit.ai with audit-logged bypass option.
8. CLAUDE.md/AGENTS.md exist but rules are prose-only — CR auto-detects these files but can't enforce prose; rules need pass/fail rewriting.
9. CR CLI (v0.4.1) is mature and used by experienced CR teams as a pre-PR step. Not installed here.
10. `auto_pause_after_reviewed_commits: 0` (current) over-corrects from CR's default of 5; `10` is a safer middle ground.

---

## Proposed Design

Five independently shippable phases. Order: **A0 → C → A → D → B**. Total mandatory effort: ~4 hours.

| Phase | What | Effort | Order rationale |
|---|---|---|---|
| **A0** | Bootstrap `.sisyphus/` versioning to parent repo | 30 min | Ships first — unlocks A's path_instructions for plan tree, gives backup, replaces Phase G |
| **C** | verify.sh status-page escalation + contract escape-hatch | 30 min | Small + insurance; ship before more PRs flow through verify.sh |
| **A** | `.coderabbit.yaml` tuning + config-validation step | 1h (2 PRs, one per repo) | Now includes path_instructions for committed `.sisyphus/` plus all the other yaml polish |
| **D** | Install `cr` CLI + CR Skills (`code-review`, `autofix`) via omo skill discovery; document recommended use in contract | 45 min | Adds tools, not mandates — verify.sh remains the merge bar; CR Skills load on-demand via omo's `skill` tool (~50 tokens each when unused) |
| **B** | CLAUDE.md / AGENTS.md enforceable rules with `/init-deep` | 45 min | Longest narrative work; ships last with maximum context from prior phases |

**Deferred (documented trip-wires below)**:
- Phase E — single custom pre-merge check ("plan reference")
- Phase G — MCP context server (CUT; replaced by A0+A; revisit only if path_instructions don't give CR enough scope-context)

**Cut entirely** (with rationale):
- Auto-labels — solo dev, no value; the 1-line `auto_pause_after_reviewed_commits: 10` tweak is rolled into Phase A
- Mandatory pre-push CLI step in loop protocol — CLI is recommended, not required
- Standalone MCP server infra — replaced by committed `.sisyphus/` + path_instructions
- Multiple custom pre-merge checks beyond the single deferred one — verify.sh enforces deterministically; AI-evaluated checks are weaker for the rules we'd move

---

## Out of scope

- Migrating already-merged PRs. Enhancements apply to subsequent PRs only.
- GitHub branch protection rule changes (called out as a follow-up; needs repo-admin action).
- Multi-author / team-scale features (auto-labels, override-restricted reviewers, etc.).
- Replacing omo with a different harness.
- Building a custom MCP server (Phase G CUT).
- Cross-repo plan mirroring (extension repo doesn't see parent's plans — accepted limitation).
- Replacing the Claude Code plugin route on opencode. The plugin is Claude-Code-specific; CR Skills + native MCP is the supported path on opencode but isn't part of this plan.

---

## Risks

- **Plan tier (Pro+ confirmed 2026-04-24)**: unlocks Phase E if we ever defer-ship it; 15 MCP slots; 10 PR reviews/hr; finishing touches available for future work.
- **Profile change `assertive` → `chill`**: Community + Martian benchmark says `chill` is the right default. Mitigate by reviewing the next 3 PRs; revert if signal drops.
- **Public exposure of `.sisyphus/` tree**: Both repos are public. Audit before A0 commit (Phase A0 step 1). Paymenter is OSS; transparency aligns with project posture.
- **Extension repo doesn't see parent plans**: accepted limitation. Mitigation: most extension PRs are small bug fixes that don't need plan context. If it bites, write a tiny mirror Action later.
- **CR rate limit (Pro+ 10/hr PR + 10/hr CLI + 100/hr chat)**: not a constraint at our usage (~1 PR-review/hr peak).
- **CR config errors silently fall back to defaults (zeroclaw#1752)**: mitigated by Phase A's `@coderabbitai configuration` validation step.
- **CR outages stick `pending` (zeroclaw#1792)**: mitigated by Phase C status-page escalation + audit-logged bypass.
- **opencode `~/.agents/skills/` discovery requires v1.1.40+** (issue #12741 fix; project-local `.agents/skills/` discovery has a known bug when CWD == worktree root). Phase D verifies version before installing CR Skills.
- **Profile inheritance**: explicit `inheritance: false` in yaml prevents future surprise org-UI bleed.

---

## Phase A0 — Bootstrap `.sisyphus/` versioning

**Goal**: commit the `.sisyphus/` tree to the parent repo. Three benefits in one PR:
1. Backup of all process artifacts (plans, contract, verify.sh, incident logs, decisions, completed boulders) — currently single-host.
2. Versioned history of governance changes — every future contract edit goes through PR review.
3. Replaces Phase G entirely — committed plans + path_instructions (Phase A) give CR the scope-context an MCP server would have, with zero infra.

### Steps

- [x] Verify `gh auth switch -u Jordanmuss99` is active (`gh api /user --jq .login` → `Jordanmuss99`).
- [x] Audit `.sisyphus/` for sensitive content:
  ```bash
  grep -RIn -E '(api[_-]?key|token|secret|password|private[_-]?key|bearer\s)' \
    /var/www/paymenter/.sisyphus/ 2>/dev/null
  ```
  Redact any hits before committing. Decision rationale and incident logs are expected to be safe.
- [x] Decide on stray file `.sisyphus/dynamic-pterodactyl-reservation-lifecycle-fixes.md` (currently at `.sisyphus/` root, not in `plans/`):
  - Recommended: `git mv` into `.sisyphus/plans/` for consistency.
- [x] Update `/var/www/paymenter/.gitignore` to exclude transient state but include process artifacts:
  ```gitignore
  # Sisyphus / omo — process artifacts are versioned, runtime state is not
  .sisyphus/boulder.json
  .sisyphus/run-continuation/
  ```
- [x] Branch off ObsidianNetwork master: `bootstrap-sisyphus-versioning`.
- [x] `git add .sisyphus/{plans,templates,completed,notepads}` and `git add .gitignore`.
- [x] Commit message:
  ```
  chore(process): commit .sisyphus/{plans,templates,completed,notepads}

  Backs up all dp-process artifacts (previously single-host on dev environment).
  Enables Phase A's path_instructions to give CR scope-context for reviews.
  Replaces dp-process-02 Phase G (MCP server) entirely.

  Tracked: plans/, templates/, completed/, notepads/
  Ignored: boulder.json (per-session state), run-continuation/ (omo internal)
  ```
- [x] Push, open PR titled `chore(process): bootstrap .sisyphus/ versioning to parent repo`.
- [ ] Wait for CR auto-review (this PR will be CR's first look at our process tree). Expect findings on:
  - `ralph-loop-verify.sh`: shellcheck issues, potential unquoted vars, etc.
  - Plan files: typos, broken cross-refs.
  - Apply critical-evaluation rule to all findings.
- [ ] Merge per `ralph-loop-verify.sh` gate.

**Exit criteria**: `.sisyphus/{plans,templates,completed,notepads}` tracked in `ObsidianNetwork:master`; transient state correctly gitignored; PR merged.
**Rollback**: revert the bootstrap PR. Local `.sisyphus/` continues to work but reverts to untracked.

---

## Phase C — verify.sh status-page escalation + contract escape-hatch

**Goal**: distinguish "CR outage" from "CR finds issues" failure modes. Vindicated by zeroclaw#1792 (real outage where PRs auto-merged because CR was broken).

### Steps

- [ ] Update `.sisyphus/templates/ralph-loop-verify.sh`:
  - When `CodeRabbit` commit-status check is `pending`, capture `startedAt` from `gh pr checks <N> --json name,startedAt`.
  - Print explicit message: `"INFO: CodeRabbit status=pending (started <ISO>); waiting. Check https://status.coderabbit.ai/ if this persists."`
  - When status has been `pending` for ≥ 15 min: escalate with `"FAIL: CR status pending for ${age_s}s. CR may be experiencing an outage. Verify at https://status.coderabbit.ai/ then re-run with --allow-actionable --reason 'CR outage YYYY-MM-DD per status page <incident-url>' if confirmed."`
- [ ] Update `.sisyphus/templates/ralph-loop-contract.md` §Hard rules:
  - Add: "**Outage bypass**: `--allow-actionable --reason 'CR outage <date> per https://status.coderabbit.ai/<incident-id>'` is permitted ONLY when CR's commit-status has been `pending` for ≥ 15 min AND status.coderabbit.ai shows an active incident. The driver MUST attach the incident URL to the audit log entry. See zeroclaw-labs/zeroclaw#1792 (2026-02) for the failure mode this rule addresses."
  - Update §Mechanical-gate prose to reference the new behavior.
- [ ] Update `.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md` with a follow-up entry:
  - Note that v2 contract observation surfaced the outage failure mode (per zeroclaw#1792).
  - Cross-reference the new outage-bypass rule.
- [ ] Self-test: temporarily edit verify.sh to treat `pass` as `pending`, run against the most recent open PR, confirm escalation message renders correctly. Revert.
- [ ] Commit + PR per the v2 contract. Title: `chore(process): verify.sh status-page escalation + outage bypass rule`.

**Exit criteria**: script differentiates outage from real failure; contract documents the bypass; audit log captures usage when bypass invoked.
**Rollback**: revert script + doc edit.

---

## Phase A — `.coderabbit.yaml` tuning + config validation

**Goal**: ship the highest-leverage CR config improvements (tone, path instructions including `.sisyphus/**`, profile, inheritance, auto_pause threshold). Validate that the config parses correctly using `@coderabbitai configuration` to avoid zeroclaw#1752-style silent default fall-back.

### Phase A.1 — ObsidianNetwork repo

- [ ] Branch `coderabbit-config-v3` off `ObsidianNetwork/Paymenter-Obsidian-Network:master`.
- [ ] Replace `.coderabbit.yaml` with §Canonical-config-A below. Key changes vs v2:
  - Add `inheritance: false` at root.
  - Add `tone_instructions` (250 chars, scope-respecting reviewer personality).
  - Change `reviews.profile: "assertive"` → `"chill"`.
  - Change `reviews.auto_review.auto_pause_after_reviewed_commits: 0` → `10`.
  - Add `reviews.path_instructions` (8 entries — themes, extensions, migrations, skeleton, plus 4 for `.sisyphus/**`).
  - Add `reviews.path_filters` (4 entries to skip non-reviewable paths).
  - Add explanatory YAML comment near `knowledge_base.learnings.scope: auto`.
- [ ] Open PR (`chore(ci): tune .coderabbit.yaml — tone, paths (incl. .sisyphus/), chill profile, no-inheritance, auto_pause=10`). Confirm CR auto-reviews itself, `CodeRabbit` status = pass.
- [ ] **Validation step (motivated by zeroclaw#1752)**: post `@coderabbitai configuration` as a comment on this PR. Verify CR's reply lists the values we shipped (no parse errors, no silent fall-back to defaults). If CR says it's using defaults: fix YAML, re-PR.
- [ ] Merge per gate.

### Phase A.2 — Extension repo

- [ ] Repeat for `Jordanmuss99/dynamic-pterodactyl` using §Canonical-config-B (paths drop the `extensions/Others/DynamicPterodactyl/` prefix and add `dp-.*` to base_branches).

### Phase A.3 — Validation

- [ ] Open one throwaway PR against `dynamic-slider/1.4.7` on ObsidianNetwork that touches only one of the two themes. Confirm CR mentions the theme-divergence path instruction. Close PR without merging.
- [ ] Open one throwaway PR that touches a `.sisyphus/plans/*.md` file. Confirm CR's review references the plan-instructions path rule (treats it as plan content, not code). Close PR.

**Exit criteria**: both repos shipped; both validation PRs confirm path_instructions fired; `@coderabbitai configuration` validates parse on both.
**Rollback**: revert config PR per repo independently.

---

## Phase D — Install `cr` CLI + document recommended pre-push usage

**Goal**: install the CodeRabbit CLI as a pre-PR safety net for non-trivial PRs. Vindicated by r/coderabbit "logic leak" workflow + Martian-benchmark "junior-reviewer-not-architect" framing (CR catches things humans miss before the PR opens).

NOT changed: loop protocol's mandatory steps — CLI is recommended, not required. The verify.sh gate remains the merge bar. CR Skills (`code-review`, `autofix`) ARE installed via omo's skill discovery: SKILL.md files surface in omo's `skill` tool description (~50 tokens each) and only load full content when the agent invokes them.

### Steps

- [ ] On dev host: `curl -fsSL https://cli.coderabbit.ai/install.sh | sh`. Verify `coderabbit --version` and `cr --version` (alias).
- [ ] Authenticate: `coderabbit auth login` (browser flow). For headless: generate Agentic API key from `app.coderabbit.ai/settings/api-keys`, export `CODERABBIT_API_KEY="cr-xxx"` in environment, then run `coderabbit auth login --api-key "$CODERABBIT_API_KEY"` once (credentials stored; subsequent commands don't need the flag). Never pass the literal key as an inline CLI argument — it ends up in shell history and `ps` output.
- [ ] Verify auth: `coderabbit auth status`.
- [ ] Sanity check: `cd /var/www/paymenter && cr --plain --base dynamic-slider/1.4.7 --type committed`. Should succeed (zero findings expected on clean tree).
- [ ] Verify omo/opencode version ≥ v1.1.40 (issue #12741 fix). Run `opencode --version`.
- [ ] Install CR Skills globally: `npx skills add coderabbitai/skills -g`. Lands `code-review/SKILL.md` + `autofix/SKILL.md` in `~/.agents/skills/` (omo's global skill discovery path).
- [ ] Verify skill discovery: `find ~/.agents/skills -name 'SKILL.md' 2>/dev/null` should list `code-review/SKILL.md` and `autofix/SKILL.md`.
- [ ] Functional test `code-review` skill — in a fresh opencode session inside `/var/www/paymenter`, type `"Review my code"`. Confirm the agent invokes `skill({ name: "code-review" })` and runs `cr --plain` against current changes.
- [ ] Functional test `autofix` skill — on a throwaway PR with a deliberately-introduced typo and an unresolved CR review thread, prompt `"Autofix CodeRabbit comments"`. Confirm the skill fetches threads via `gh api graphql`, applies the fix, creates one consolidated commit, and surfaces the option to push.
- [ ] (Optional polish, defer if budget tight) Per-agent skill filtering — restrict `autofix` to `Sisyphus` so quick `Hephaestus` tasks can't accidentally invoke thread-fetch workflows. Add to omo's `~/.config/opencode/opencode.json`:
  ```json
  {
    "agent": {
      "Hephaestus": { "permission": { "skill": { "autofix": "deny" } } },
      "Sisyphus":  { "permission": { "skill": { "autofix": "allow", "code-review": "allow" } } }
    }
  }
  ```
- [ ] Update `.sisyphus/templates/ralph-loop-contract.md`:
  - Add §Tooling section listing:
    - CLI install + auth: `curl -fsSL https://cli.coderabbit.ai/install.sh | sh` and `coderabbit auth login`.
    - Skills install: `npx skills add coderabbitai/skills -g` (requires opencode ≥ v1.1.40).
    - Verify commands: `coderabbit auth status`, `coderabbit --version`, `find ~/.agents/skills -name 'SKILL.md' 2>/dev/null`.
  - Add to §Loop-protocol: **"Pre-push CLI review (RECOMMENDED, not required)"** for any PR that:
    - Touches more than 2 files, OR
    - Touches security-sensitive code (auth, payments, anything under `app/Http/Middleware/`, `app/Auth/`, gateway extensions), OR
    - Touches shared infrastructure (`.coderabbit.yaml`, `.sisyphus/templates/`, `.gitignore`).
  - Command (CLI direct): `cr --plain --base <integration-branch> --type committed`. Apply critical-evaluation rule to findings; fix or rebase before pushing.
  - Command (natural language via opencode): `"Review my code"` — invokes the `code-review` skill, equivalent to running the CLI.
  - Add to §Post-CR-review thread handling: when CR posts findings on an open PR, the natural-language alternative to manually applying each fix is `"Autofix CodeRabbit comments"` (invokes the `autofix` skill — fetches unresolved threads via `gh api graphql`, applies fix prompts in batch or interactive mode, creates one consolidated commit). The critical-evaluation rule still applies; review batch mode's output before pushing.
  - Note that pre-push CLI review uses the SEPARATE 10-reviews/hr Pro+ CLI bucket, not the 10-reviews/hr PR-review bucket. Both refill at one unit per 6 minutes on Pro+.
- [ ] Commit + PR. Title: `chore(process): install cr CLI + document recommended pre-push usage in contract`.

**Exit criteria**: `cr --plain` runs successfully on Paymenter fork; CR Skills (`code-review`, `autofix`) discoverable by omo and triggered by natural language; contract documents recommended use cases for both CLI and skills; loop protocol unchanged for mandatory steps.
**Rollback**: `rm $(which coderabbit)` and revert contract edit.

---

## Phase B — CLAUDE.md / AGENTS.md enforceable rules (with /init-deep)

**Goal**: convert prose-only rules in our existing CLAUDE.md / AGENTS.md files into pass/fail criteria CR can enforce via auto-detection. Triple purpose — same files feed CR + omo + future-Claude.

CR auto-detects (per docs): `**/CLAUDE.md`, `**/AGENTS.md`, `**/.cursorrules`, `.github/copilot-instructions.md`, `**/GEMINI.md`, `**/.cursor/rules/*`, `**/.windsurfrules`, `**/.clinerules/*`, `**/.rules/*`, `**/AGENT.md`. Scope is directory-tree-based — root files apply everywhere; nested files apply to their subtree only.

omo also reads `AGENTS.md` (project + global) and falls back to `CLAUDE.md` (Claude-Code compat). Same files do triple duty.

### Steps

- [ ] **Bootstrap with `/init-deep`** (omo command): generates hierarchical `AGENTS.md` files throughout the project. Any files we already have at root or nested level remain as the canonical version; `/init-deep` extends, doesn't overwrite.
- [ ] Inventory:
  ```bash
  find /var/www/paymenter -maxdepth 4 \( -name 'CLAUDE.md' -o -name 'AGENTS.md' -o -name 'AGENT.md' \) 2>/dev/null
  ```
  And same on the extension repo.
- [ ] For each file, classify each existing rule:
  - **Enforceable**: rewrite as `- FAIL when: <condition>. Rationale: <why>.`
  - **Informational**: leave as prose.
  - **Stale / contradictory**: fix or remove (e.g., `CLAUDE.md` references `app/Extensions/...` when actual is `extensions/Others/...`).
- [ ] In each file, add a top-level section:
  ```markdown
  ## Enforceable rules (CodeRabbit reads these)

  - FAIL when: <condition>. Rationale: <why>.
  - FAIL when: ...
  ```
  This is the section CR's auto-detection will key on. The rest of the file remains free-form.
- [ ] Specific known candidates to encode (audit will surface more):
  - **Root `/var/www/paymenter/CLAUDE.md`** (create if absent): "FAIL when commits to nested extension repo are made from outer Paymenter repo's working tree."
  - **`extensions/AGENTS.md`**: "FAIL when a new `composer.json` is added under `extensions/<Type>/<Name>/` (single composer at root manages everything)."
  - **`extensions/Others/DynamicPterodactyl/CLAUDE.md`**: "FAIL when Pterodactyl API responses are cached. FAIL when pricing logic is added to this extension's admin (pricing moved to Paymenter core per DECISIONS.md)."
  - **`extensions/Others/DynamicPterodactyl/AGENTS.md`**: "FAIL when files under `skeleton/` are modified without commit-message justification. FAIL when server provisioning is reimplemented here (delegate to `extensions/Servers/Pterodactyl/`)."
- [ ] Commit each repo's CLAUDE/AGENTS edits as a small focused PR per repo. Confirm CR auto-reviews referenced rules fire alongside path_instructions.
- [ ] Document the "rewrite prose into pass/fail" pattern in `.sisyphus/templates/ralph-loop-contract.md` so future plans know to use this format.

**Exit criteria**: each guideline file has at least one `- FAIL when:` rule. Next dp-NN PR's CR review surfaces a violation if intentionally introduced.
**Rollback**: revert the docs PRs. CR falls back to prose rules; omo unaffected.

---

## Deferred phases (with documented trip-wires)

### Phase E (deferred) — Single custom pre-merge check

**Trip-wire to revisit**: if we observe the driver forgetting to reference plans in PR descriptions on >2 consecutive dp-NN PRs, ship the single check below.

**The one check worth shipping** (others rejected — verify.sh enforces deterministically; AI-evaluated checks are weaker):

```yaml
reviews:
  pre_merge_checks:
    override_requested_reviewers_only: false   # solo-author flow
    custom_checks:
      - name: "Plan Reference"
        mode: "warning"
        instructions: |
          Pass when the PR title or branch name contains a dp-NN-* identifier
          OR the PR description references a file in .sisyphus/plans/.
          Fail when neither is present (gives the driver one shot to amend the
          PR description before merge). This check is the only way to catch
          a missing plan reference at PR time, since verify.sh runs only
          immediately before merge and can't suggest title/description fixes.
```

Pro+ allows 20 custom checks; budget for this one is trivial. Defer until needed.

### Phase G (CUT) — MCP context server for plan tree

**Replaced by**: A0 (commit `.sisyphus/`) + A (`path_instructions` for `.sisyphus/plans/**`). CR auto-detects committed plan files when a PR's diff touches them, AND treats them as referenced context per path_instructions when the branch matches a plan name.

**Trip-wire to revisit**: if CR systematically misses plan-defined scope on >20% of dp-NN reviews despite path_instructions and committed plans, reconsider standing up a separately-hosted MCP server. At that point we'd:
- Use `@modelcontextprotocol/server-filesystem` (read-only, restricted dirs) or write a tiny custom server (~50 lines Node).
- Host via Cloudflare Tunnel (or similar) with auth.
- Register at `app.coderabbit.ai/integrations?tab=mcp` (Pro+ has 15 slots; we'd use 1).
- Set `knowledge_base.mcp.usage: enabled` in yaml (default `auto` disables MCP for public repos).

Cost we deferred: 3-5h infra + ongoing tunnel/auth maintenance. Not worth it until we see the limitation bite.

---

## Canonical config diffs

### Canonical-config-A — `ObsidianNetwork/Paymenter-Obsidian-Network/.coderabbit.yaml`

```yaml
# yaml-language-server: $schema=https://coderabbit.ai/integrations/schema.v2.json

# Explicit no-inheritance (default is OFF; explicit prevents future surprise org-UI bleed).
inheritance: false

language: en-US

# 250-char reviewer-personality field. Cuts rejection-reasoning rounds.
tone_instructions: "Respect PR scope boundaries. Out-of-scope findings should be flagged as deferred (suggest the matching dp-NN plan in .sisyphus/plans/), not demanded. The author may reject findings with a 3-part rationale (claim → why-wrong → intended-design)."

reviews:
  profile: chill            # was: assertive. Community + Martian benchmark favor chill for default.
  commit_status: true
  fail_commit_status: true
  request_changes_workflow: true

  auto_review:
    enabled: true
    auto_incremental_review: true
    auto_pause_after_reviewed_commits: 10   # was: 0 (over-correction). 10 = 2x CR default; @coderabbitai review escape if hit.
    drafts: false
    base_branches:
      - "dynamic-slider.*"
    ignore_title_keywords:
      - "WIP"
      - "[skip review]"
    ignore_usernames: []

  path_filters:
    - "!**/storage/logs/**"
    - "!bootstrap/cache/**"
    - "!.sisyphus/run-continuation/**"

  path_instructions:
    # --- Code paths ---
    - path: "themes/{default,obsidian}/**"
      instructions: |
        Both themes MUST stay in sync. If only one theme is modified, flag the divergence.
        Shared UI should live in resources/views/components/ and be @included from each theme.
    - path: "extensions/Others/DynamicPterodactyl/**"
      instructions: |
        This is a nested git repo with its own .coderabbit.yaml.
        Do not reimplement server provisioning (delegate to extensions/Servers/Pterodactyl/).
        Do not cache Pterodactyl API responses (settled decision per extension's DECISIONS.md).
        Pricing logic lives in Paymenter core, not in this extension's admin.
    - path: "extensions/Others/DynamicPterodactyl/skeleton/**"
      instructions: |
        Stale 2025-11-28 pre-implementation scaffold. Any change here is almost always a mistake;
        demand justification in the commit message before approving.
    - path: "**/database/migrations/**"
      instructions: |
        Destructive operations (drop/truncate) in up() must have a corresponding restoration
        in down(). Flag any migration that violates this.

    # --- .sisyphus/ tree (committed in dp-process-02 Phase A0) ---
    - path: ".sisyphus/plans/**"
      instructions: |
        These are dp-NN plan documents. When this PR's branch matches a plan name
        (e.g., dp-core-02-blade-architecture), read the matching plan. The plan's
        Scope, Out-of-scope, and Status sections define what is acceptable for this PR.
        Findings outside that scope should be flagged as deferred to the appropriate
        dp-NN, not demanded as fixes.
    - path: ".sisyphus/templates/**"
      instructions: |
        These are the /ralph-loop process contracts (ralph-loop-contract.md and
        ralph-loop-verify.sh). Treat changes here as governance changes — apply
        assertive review to detect unintended weakening of merge-gate rules.
        Cross-reference .sisyphus/notepads/dp-process-audit/incident-2026-04-24.md
        before approving any rule removal.
    - path: ".sisyphus/notepads/**"
      instructions: |
        Decision notepads and incident logs. Read for historical context when
        relevant to changes elsewhere in the PR. Do not treat as code; do not
        suggest refactors. Out-of-scope for review except for typo / clarity nits.
    - path: ".sisyphus/completed/**"
      instructions: |
        Archived boulder JSON state from completed plans. Read-only history;
        out-of-scope for review.

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

knowledge_base:
  # learnings.scope = auto correctly isolates public-repo learnings (this is a public fork).
  # Do NOT change to "global" without coordinating with the extension repo's config.
  learnings:
    scope: auto
```

### Canonical-config-B — `Jordanmuss99/dynamic-pterodactyl/.coderabbit.yaml`

Identical to config A except:
- `auto_review.base_branches`: `["dynamic-slider.*", "dp-.*"]`
- `path_filters`: drop the `extensions/...` line; add `"!skeleton/**"` directly.
- `path_instructions` for code paths: rewrite paths relative to extension root (drop `extensions/Others/DynamicPterodactyl/` prefix).
- `path_instructions` for `.sisyphus/**`: omit (extension repo has no plan tree).
- `pre_merge_checks.title.requirements`: same Conventional Commits pattern.

---

## Status

- [ ] Phase A0: bootstrap `.sisyphus/` to ObsidianNetwork master (PR + audit + .gitignore)
- [ ] Phase C: status-page escalation in verify.sh + outage bypass rule in contract (PR)
- [ ] Phase A.1: ObsidianNetwork `.coderabbit.yaml` tuned + `@coderabbitai configuration` validates (PR)
- [ ] Phase A.2: dynamic-pterodactyl `.coderabbit.yaml` tuned + validated (PR)
- [ ] Phase A.3: theme-divergence + plan-path validation PRs (throwaway, no merge)
- [ ] Phase D: `cr` CLI installed + CR Skills (`code-review`, `autofix`) discoverable + contract updated (PR)
- [ ] Phase B: `/init-deep` run + enforceable rules in CLAUDE.md / AGENTS.md (PRs per repo)

**Deferred (with trip-wires)**:
- [ ] Phase E (deferred): "Plan Reference" custom check — ship if driver forgets plan refs on >2 consecutive dp-NN PRs
- [ ] Phase G (CUT): MCP server — revisit if CR misses scope on >20% of reviews despite committed plans + path_instructions

---

## References

### CodeRabbit official docs

- Configuration reference: https://docs.coderabbit.ai/reference/configuration
- Path instructions: https://docs.coderabbit.ai/configuration/path-instructions
- Pre-merge checks: https://docs.coderabbit.ai/pr-reviews/pre-merge-checks
- Custom checks: https://docs.coderabbit.ai/pr-reviews/custom-checks
- Code guidelines (CLAUDE.md/AGENTS.md auto-detection): https://docs.coderabbit.ai/knowledge-base/code-guidelines
- Learnings: https://docs.coderabbit.ai/knowledge-base/learnings
- Configuration inheritance: https://docs.coderabbit.ai/configuration/configuration-inheritance
- Auto-review: https://docs.coderabbit.ai/configuration/auto-review
- CLI: https://docs.coderabbit.ai/cli/index
- Skills: https://docs.coderabbit.ai/cli/skills
- MCP integration (CR as client): https://docs.coderabbit.ai/integrations/mcp-servers
- MCP as knowledge source: https://docs.coderabbit.ai/knowledge-base/mcp-context
- Plans + rate limits: https://docs.coderabbit.ai/management/plans
- Repository settings: https://docs.coderabbit.ai/guides/repository-settings
- Changelog: https://docs.coderabbit.ai/changelog

### Independent benchmarks + user signals (refreshed 2026-04-24)

- Martian Code Review Bench (cited Medium 2026-04-14 "honest comparison"): CR has highest F1 score 51.2%, precision 49.2% across ~300k PRs.
- r/cursor independent OSS benchmark: CR ranks #1 in F1 score.
- r/coderabbit "logic leak" example: real privacy bug catch via CLI pre-PR workflow.
- zeroclaw-labs/zeroclaw#1792 (Feb 2026): real CR outage example.
- zeroclaw-labs/zeroclaw#1752 (Feb 2026): `.coderabbit.yaml` parse error → silent default fall-back.
- Kingy AI 2026-04-11 "Quietly Becoming Essential": three-layer (PR/IDE/CLI) framing.

### opencode + omo

- opencode skills docs: https://opencode.ai/docs/skills/
- opencode rules docs: https://dev.opencode.ai/docs/rules
- opencode native MCP support (PR #1170): https://github.com/sst/opencode/issues/361
- opencode `~/.agents/skills/` discovery bug (issue #12741): https://github.com/anomalyco/opencode/issues/12741
- omo (oh-my-openagent) repo: https://github.com/code-yeongyu/oh-my-openagent

### Predecessor + related

- Predecessor plan: `.sisyphus/plans/dp-process-01-ralph-loop-v2.md`
- Incident log: `.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md`
- Files touched by this plan: `.coderabbit.yaml` (both repos), `.sisyphus/templates/ralph-loop-verify.sh`, `.sisyphus/templates/ralph-loop-contract.md`, root `.gitignore`, `**/CLAUDE.md`, `**/AGENTS.md`, `.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md`, `~/.config/opencode/opencode.json` (optional Phase D polish)
