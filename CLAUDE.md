# Paymenter Root — CLAUDE.md

High-level context for AI agents working on the outer Paymenter fork. For detailed stack, structure, and commands, see `AGENTS.md`.

## Enforceable rules (CodeRabbit reads these)

- FAIL when: a commit touches files under `extensions/Others/DynamicPterodactyl/` from the outer Paymenter working tree. Rationale: `extensions/Others/DynamicPterodactyl/` is a nested git repo with its own `.git/`. Changes there must be committed from inside that directory (`cd extensions/Others/DynamicPterodactyl && git commit`).
- FAIL when: `app/Filament/` is created or files are placed in it. Rationale: the Filament panel is wired to `app/Admin/` — placing files in `app/Filament/` silently does nothing.
- FAIL when: a migration drops or renames an existing `ptero_*` table without a corresponding `down()` rollback. Rationale: extension tables require coordinated rollbacks with `uninstalled()`.
