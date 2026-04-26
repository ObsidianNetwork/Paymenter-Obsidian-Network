# dp-19 — Master / deployment-branch divergence: document + guard

**Source**: dp-audit-2026-04-26 finding F6 (REFRAMED).
**Scope**: `/var/www/paymenter/` (outer Paymenter fork). Touches root `AGENTS.md` / `CLAUDE.md` plus optional check-script.
**Type**: Documentation + light dev-tooling. No production code changes.
**Effort**: S (1-2 hours).
**Severity**: medium (dev-experience; no production impact).
**Suggested branch**: `dp-19-branch-divergence-docs`.

---

## Problem

The 2026-04-26 audit ran `phpunit` + `lsp_diagnostics` from `master`. It reported 16 failures + 35 errors + "missing class `App\Rules\DynamicSliderPricingRule`". Worktree verification at `dynamic-slider/1.4.7` (the actual deployment branch) confirms the file IS present (blob `415ccab`) and the dp-NN feature work all lives there. `master` is the upstream Paymenter mirror + `.sisyphus/` governance only. So `master` doesn't pass tests — by design but undocumented. Every future audit run from master will hit the same false-alarm cost (~5 min wasted in this audit).

## Goal

1. Document the branch strategy in root `AGENTS.md`.
2. Add a CLAUDE.md `FAIL when:` rule so future agents check before running code-quality tools from master.
3. Optional: tiny `audit-helper.sh` that warns when run from `master`.

## Out of scope

- Merging `dynamic-slider/1.4.7` into `master`. Branches are intentionally divergent — don't fight that.
- Pre-commit hooks or CI changes. Documentation-first.

## Edits

### Edit 1 — `/var/www/paymenter/AGENTS.md`: add "Branch strategy" section near the top

Content (verbatim — subagent uses this exact text):

```markdown
## Branch strategy

This is a fork of `paymenter/paymenter` with an active deployment branch.

| Branch | Role | What's on it |
|---|---|---|
| `master` | Upstream Paymenter mirror + `.sisyphus/` governance only | No fork-specific feature work. Tests will FAIL on master because dp-core-01 patches and other deployment changes don't live here. |
| `dynamic-slider/1.4.7` | Active deployment branch | All dp-NN feature work, dp-core-01, dp-core-02, the extension repo's `.coderabbit.yaml`, etc. |

**For any code-quality check** (`phpunit`, `composer audit`, `lsp_diagnostics`, `phpstan`): switch to `dynamic-slider/1.4.7` first, OR use `git worktree add /tmp/paymenter-deploy dynamic-slider/1.4.7`.

**For governance changes** (`.sisyphus/` plans, contract, verify.sh): stay on `master` — these don't run code, so the missing fork patches don't matter.
```

### Edit 2 — `/var/www/paymenter/CLAUDE.md`: add one FAIL-when rule

Content (verbatim):

```markdown
- FAIL when: a code-quality check (phpunit, composer audit, lsp_diagnostics, phpstan, etc.) is run from `master` without first switching to `dynamic-slider/1.4.7` or using a worktree. Rationale: `master` mirrors upstream Paymenter only; tests will fail on missing-class errors that are false alarms. See AGENTS.md "Branch strategy".
```

### Edit 3 (OPTIONAL) — `/var/www/paymenter/.sisyphus/templates/audit-helper.sh`

```bash
#!/usr/bin/env bash
# audit-helper.sh — warn if running code-quality checks from master
set -euo pipefail
branch=$(git -C "$(dirname "$0")/../.." branch --show-current)
case "$branch" in
  dynamic-slider/*) exit 0 ;;
  master)
    echo "WARN: on master. Code-quality checks will fail because deployment patches live on dynamic-slider/1.4.7." >&2
    echo "      Either: git checkout dynamic-slider/1.4.7" >&2
    echo "      Or:    git worktree add /tmp/paymenter-deploy dynamic-slider/1.4.7" >&2
    exit 1 ;;
  *) echo "INFO: on feature branch $branch. Confirm it's based off a deployment branch." >&2; exit 0 ;;
esac
```

`chmod +x` after creation.

## Acceptance

```bash
cd /var/www/paymenter
grep -A3 'Branch strategy' AGENTS.md         # section visible
grep 'FAIL when.*master' CLAUDE.md           # rule visible
# (optional) test -x .sisyphus/templates/audit-helper.sh && .sisyphus/templates/audit-helper.sh
```

## Commit

Single commit. Title: `docs(branch-strategy): document master vs dynamic-slider/1.4.7 split`.

Per Phase B convention, root `AGENTS.md` / `CLAUDE.md` edits are committed via PR (CR auto-reviews them). The `.sisyphus/templates/` script is `.sisyphus/`-internal so doesn't need a PR if added.

## Delegation

`task(category="quick", load_skills=[], run_in_background=false, ...)`. Trivial doc edits. Quick category, foreground.

## Status

- [ ] Plan written (you are here)
- [ ] Delegated to subagent
- [ ] AGENTS.md "Branch strategy" section added
- [ ] CLAUDE.md FAIL-when rule added
- [ ] (Optional) audit-helper.sh added + chmod +x
- [ ] Acceptance grep checks pass
- [ ] PR opened (root governance files; per Phase B convention)
- [ ] CR review cycle complete
- [ ] PR merged
- [ ] `dp-audit-2026-04-26.md` Status section gets a cross-reference to this plan
