2026-04-26: For theme-sync validation, a single harmless comment in `themes/default/theme.php` is sufficient to trigger path-based review rules without affecting runtime behavior.


2026-04-26: omo `permission` schema (per `~/.cache/opencode/packages/oh-my-openagent.../node_modules/oh-my-openagent/dist/oh-my-opencode.schema.json` and the upstream configuration.md) accepts only `edit`, `bash`, `webfetch`, `doom_loop`, `external_directory` and is `additionalProperties: false`. The plan's suggested `agent.{name}.permission.skill.{skill-name}: deny` block (Phase D, line 260) is **not a valid omo config shape** — it would silently no-op or be rejected on validation. Per-agent skill filtering as written cannot be implemented.

2026-04-26: omo skill filtering is **global only**, via top-level keys: `disabled_skills: [...]`, `skills.enable: [...]`, `skills.disable: [...]`. Per-agent restriction is possible only by setting `agents.{name}.tools` to a whitelist that excludes the `skill` tool, which would disable **all** skill invocations for that agent (e.g. blocking Hephaestus from `code-review` AND `autofix` together) — too coarse for the plan's intent.

2026-04-26: `coderabbit review --plain --base <branch> --type committed` takes 4-6 minutes end-to-end on a working tree of this size (sandbox setup + multi-file review). The default 60-90s bash timeout will cut it off at "Preparing sandbox" or "Reviewing". For sanity checks: run inside `tmux` and capture-pane after ≥ 5 minutes, or use a longer Bash `timeout` parameter (≥ 360000 ms).

2026-04-26: `cr` is the alias for the `coderabbit` CLI but the alias does NOT proxy `--plain` flag at the top level. Use `coderabbit review --plain ...` (subcommand-first), not `cr --plain ...`. Help output: `coderabbit review --help`.