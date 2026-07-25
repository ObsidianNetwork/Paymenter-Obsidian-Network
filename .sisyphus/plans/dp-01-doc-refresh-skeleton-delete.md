# dp-01-doc-refresh-skeleton-delete — DynamicPterodactyl Doc Refresh + Skeleton Delete

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (nested git repo)
**Type**: Pure mechanical doc cleanup. Closes out dp-01-shippable-polish Change 3, the only remaining unfinished work in the dp-NN backlog.
**Predecessor**: `dp-01-shippable-polish.md` (Change 1 ExtensionMeta + Change 2 route throttle already shipped).
**Active branch (after `git checkout`)**: `dp-01-doc-refresh-skeleton-delete` (already created off `dynamic-slider`).

---

## Why this exists

dp-01 Change 3 has been pending since 2026-04-21. dp-07/09/11/13 shipped subsequently and (a) finished some of dp-01's intended doc cleanup ahead of time, (b) introduced new drift (e.g. `PricingCalculatorService` renamed to `SliderConfigReaderService` in dp-11; migration count grew from 5 to 7). Net effect: the actually-needed work is smaller than dp-01 originally specified, but slightly different.

The full investigation behind this scoping is in `.sisyphus/notepads/dp-process-02-ralph-loop-v2-enhancements/learnings.md` (audit pass entry, 2026-04-26).

## Out of scope

- 01-DATABASE.md and 04-EVENTS.md still describe `ptero_pricing_configs` as a current feature. That's dp-07 doc-debt, not dp-01 scope. Defer to a future doc-only PR.
- CHANGELOG.md release-version cut for the post-3.1.0 work (currently in `[Unreleased]`). That's a release decision, not a polish closeout.
- README.md architecture-diagram redraw beyond the one inaccurate line.

---

## Pre-flight (the agent should verify before editing)

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git branch --show-current   # MUST be: dp-01-doc-refresh-skeleton-delete
git status --short          # MUST be: clean (or only the edits this plan introduces)
ls Services/                # MUST contain SliderConfigReaderService.php (NOT PricingCalculatorService.php)
ls Admin/Resources/         # MUST contain AlertConfigResource and ReservationResource only (no PricingConfig)
ls database/migrations/ | wc -l   # MUST be 7
find skeleton -type f       # MUST return nothing (skeleton is empty dirs only)
```

If any check fails, STOP and update this plan.

---

## Edit 1 — README.md (4 sites)

### 1a. Architecture diagram pricing line (around line 33)

**Find** (exact):

```
│  ├── Interim pricing scaffolding pending dp-core-01                 │
```

**Replace with**:

```
│  ├── Slider config reads via SliderConfigReaderService             │
```

### 1b. Pricing-language paragraph (around line 86)

**Find** (exact):

```
Paymenter core is the intended pricing authority for `dynamic_slider` options. Until the fork-only core patches in `dp-core-01` land, this extension keeps `PricingCalculatorService` as interim pricing scaffolding to compensate for known core defects.
```

**Replace with**:

```
Paymenter core is the pricing authority for `dynamic_slider` options. The fork-only core patches in `dp-core-01` (merged) handle slider pricing at runtime via `Plan::dynamicSliderBasePrice()` and `ConfigOption::calculateDynamicPriceDelta()`. This extension's `SliderConfigReaderService` reads slider config metadata; `PricingConfigValidator` is retired in favour of core `DynamicSliderPricingRule`.
```

### 1c. File Structure — migration count (around line 100)

**Find** (exact):

```
├── database/migrations/            # 4 migration files
```

**Replace with**:

```
├── database/migrations/            # 7 migration files
```

### 1d. File Structure — Admin/Resources (around line 103)

**Find** (exact):

```
│   └── Resources/                  # PricingConfig, Reservation, Alert
```

**Replace with**:

```
│   └── Resources/                  # AlertConfig, Reservation
```

---

## Edit 2 — AGENTS.md (5 sites)

### 2a. File-structure tree, services line (around line 21)

**Find**:

```
├── Services/                    # 7 services, 1355 LOC (business logic core)
```

**Replace with**:

```
├── Services/                    # 8 services (business logic core; pricing reads via SliderConfigReaderService)
```

### 2b. File-structure tree, migrations line (around line 22)

**Find**:

```
├── database/migrations/         # 5 migrations, all `ptero_*` tables
```

**Replace with**:

```
├── database/migrations/         # 7 migrations, all `ptero_*` tables
```

### 2c. File-structure tree, skeleton line (around line 27)

**Delete entirely** (the directory is being removed in this PR):

```
├── skeleton/                    # INACTIVE scaffold (pre-implementation), see notes
```

### 2d. "Where to look" table — Pricing math row (around line 44)

**Find**:

```
| Pricing math | `Services/PricingCalculatorService.php` | `07-PRICING-MODELS.md` |
```

**Replace with**:

```
| Pricing math | core `Plan::dynamicSliderBasePrice()` + `ConfigOption::calculateDynamicPriceDelta()` (dp-core-01); reads via `Services/SliderConfigReaderService.php` | `07-PRICING-MODELS.md` |
```

### 2e. Conventions list — skeleton bullet (around line 64)

**Delete entirely**:

```
- **Do not edit `skeleton/`** — it's a stale 2025-11-28 pre-implementation scaffold (only `DynamicPterodactyl.php`, `Services/ResourceCalculationService.php`, `routes/api.php`), superseded by root files. Safe to delete; retained for historical reference only.
```

### 2f. "Known stale references in sibling docs" section (lines ~68-72)

**Delete the entire section** (heading + 4 bullets + blank lines). The references it cites (CLAUDE.md:63 `app/Extensions/...`, README.md `Filament/`, `Jobs/`) no longer exist in those files — the section is itself stale.

Verify before deleting:

```bash
grep -nE 'app/Extensions/Others|Filament/|Jobs/' README.md CLAUDE.md
# MUST return empty — confirms section is stale-stale
```

### 2g. Enforceable rules — skeleton FAIL rule (around line 86)

**Delete entirely**:

```
- FAIL when: files under `skeleton/` are modified. Rationale: `skeleton/` is a stale 2025-11-28 pre-implementation scaffold retained for historical reference only; edit live root files instead.
```

---

## Edit 3 — CLAUDE.md (1 site)

### 3a. Enforceable rules — skeleton FAIL rule (around line 228)

**Delete entirely**:

```
- FAIL when: files under `skeleton/` are modified. Rationale: stale scaffold; only root-level files are canonical.
```

---

## Edit 4 — Delete skeleton/ directory

```bash
git rm -r skeleton/
```

Skeleton is empty subdirs only (8 directories, 0 files). No filesystem-side data loss.

---

## Edit 5 — (skip) .gitignore

Plan dp-01 step 3f asked for vendor/, .env, .env.local, .phpunit.result.cache, .phpunit.cache/. **Already present** — verified pre-flight. No edit needed.

---

## Edit 6 — (skip) CHANGELOG.md release version

dp-01 Change 3b asked to move `[Unreleased]` content under a new `[3.1.0] — 2026-04-21` heading. **Already done** — `[3.1.0]` heading already exists; current `[Unreleased]` correctly contains post-3.1.0 work (dp-09 through dp-13). The release-version cut for that work is a separate decision.

---

## Commit

One commit, one PR. Title:

```
chore(docs): refresh README + AGENTS for post-dp-13 reality; delete empty skeleton/
```

Commit body:

```
Closes the long-pending dp-01-shippable-polish Change 3.

README.md:
- Architecture diagram: drop "Interim pricing scaffolding pending dp-core-01" (dp-core-01 merged)
- Pricing-language paragraph: rewrite for current state (SliderConfigReaderService, retired PricingConfigValidator)
- File Structure: 4 -> 7 migrations; Admin/Resources drops PricingConfig (retired by dp-09)

AGENTS.md:
- File-structure tree: 7 services -> 8; 5 migrations -> 7; remove skeleton/ entry
- "Where to look" pricing-math row: PricingCalculatorService -> SliderConfigReaderService + dp-core-01 core methods
- Drop "Do not edit skeleton/" convention (directory deleted)
- Drop "Known stale references in sibling docs" section (its references no longer exist; the section was itself stale)
- Drop "FAIL when files under skeleton/ are modified" rule (directory deleted)

CLAUDE.md:
- Drop "FAIL when files under skeleton/ are modified" rule (directory deleted)

skeleton/:
- git rm -r (empty subdirs only, no file content loss)

Author: Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>
```

---

## Push + PR

```bash
git push -u origin dp-01-doc-refresh-skeleton-delete
gh pr create --base dynamic-slider --head dp-01-doc-refresh-skeleton-delete \
  --title "chore(docs): refresh README + AGENTS for post-dp-13 reality; delete empty skeleton/" \
  --body "Closes dp-01-shippable-polish Change 3 (the only remaining unfinished work in the dp-NN backlog).

See plan: .sisyphus/plans/dp-01-doc-refresh-skeleton-delete.md (in outer Paymenter repo)."
```

---

## CR review cycle (ralph-loop)

After push, run the standard ralph-loop verify gate:

```bash
.sisyphus/templates/ralph-loop-verify.sh <PR_NUMBER>
```

When CodeRabbit posts review threads:

1. Apply the critical-evaluation rule from `.sisyphus/templates/ralph-loop-contract.md`.
2. For each thread: validate independently, fix or push back with rationale.
3. Repeat verify-gate until clean.
4. Squash-merge via `gh pr merge <N> --squash --delete-branch --auto`.

---

## Acceptance criteria

After merge, on `dynamic-slider`:

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
test ! -d skeleton                                       # skeleton/ gone
grep -c 'PricingCalculatorService' README.md AGENTS.md CLAUDE.md   # 0
grep -c 'skeleton/' AGENTS.md CLAUDE.md                  # 0 (PROGRESS.md + CHANGELOG.md historical refs OK)
grep '7 migration' README.md AGENTS.md                   # both files match
grep 'SliderConfigReaderService' README.md AGENTS.md     # both files reference current name
```

Then mark `dp-01-shippable-polish` and this plan as fully shipped in PROGRESS.md.

---

## Status

- [x] Plan written
- [x] Delegated to subagent (`bg_1c2dd8c7`, Sisyphus-Junior, category `unspecified-low`)
- [x] Subagent applied edits 1-4 + commit + push + PR (extension repo PR #16)
- [x] CR review cycle complete (CodeRabbit returned APPROVED with no findings)
- [x] PR merged on `dynamic-slider` (squash SHA `e2034a48`, merged 2026-04-26T10:15:03Z)
- [x] PROGRESS.md updated by subagent (commit `5e274be` on `dynamic-slider`)
- [x] dp-01-shippable-polish.md cross-linked to this closeout (orchestrator update)

## Closeout (2026-04-26)

**Result**: All dp-01 Change 3 work shipped end-to-end via this plan.

**Extension repo PR #16** (`Jordanmuss99/dynamic-pterodactyl`):
- Title: `chore(docs): refresh README + AGENTS for post-dp-13 reality; delete empty skeleton/`
- Squash SHA: `e2034a485cb76cf1607d8ab776f9c0406297e4df`
- Author: `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`
- CodeRabbit verdict: APPROVED (zero findings)
- 3 files modified (README.md, AGENTS.md, CLAUDE.md), `skeleton/` removed

**One deviation from plan**: Edit 4 specified `git rm -r skeleton/`, but `skeleton/` contained only empty subdirectories and was never tracked by git. Subagent used `rm -rf skeleton/` instead. End result identical — directory gone, acceptance gate passes (`test ! -d skeleton`), no file content lost.

**Acceptance gate (all passed post-merge)**:
- `test ! -d skeleton` → PASS
- `grep -c PricingCalculatorService README.md AGENTS.md CLAUDE.md` → 0/0/0
- `grep -c skeleton/ AGENTS.md CLAUDE.md` → 0/0
- `grep '7 migration' README.md AGENTS.md` → both match
- `grep SliderConfigReaderService README.md AGENTS.md` → both match

**Bonus mutation by subagent**: stale `PricingCalculatorService (service)` line in CLAUDE.md's Naming section was deleted to satisfy the acceptance gate's `0 hits` requirement. Plan didn't explicitly list this site, but the acceptance criteria mandated zero residual hits. Subagent correctly inferred the mutation was needed and applied it.
