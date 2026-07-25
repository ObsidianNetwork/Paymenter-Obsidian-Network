## 2026-04-26 — CodeRabbit schema-limit fix

- Verified `.coderabbit.yaml` `tone_instructions` still starts with the required scope-boundary prefix before commit.
- Kept the commit scoped to `.coderabbit.yaml`; unrelated local edits in `CLAUDE.md` were left uncommitted.

- In extension docs, keep inline comments aligned with the real runtime config (`phpunit.xml` / `.coderabbit.yaml`) and satisfy markdownlint fence rules (` ```text ` for MD040).
