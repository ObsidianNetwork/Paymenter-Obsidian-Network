# dp-process-03 pre-flight sampling

Date: 2026-04-28

## 1) CR `author.login` confirmation

Sampled PRs:
- `Jordanmuss99/dynamic-pterodactyl#20`
- `ObsidianNetwork/Paymenter-Obsidian-Network#18`

Expanded confirmation pass:
- Queried the last 30 PRs in both repos across top-level PR comments, formal reviews, and review-thread comments.
- Observed CodeRabbit login variants: `coderabbitai` only.
- Observed `coderabbitai[bot]`: none.

Conclusion:
- Current live data in both repos uses `coderabbitai` only.
- Keep the defensive `coderabbitai OR coderabbitai[bot]` predicate in `verify.sh` anyway, because dp-process-03 explicitly wants cross-deployment tolerance and the extra branch is low-cost.

## 2) CR ack-message format sampling

Plan assumption under test:
- `startswith("Actions performed:")`

Five recent auto-ack samples:
1. `Jordanmuss99/dynamic-pterodactyl#12` @ `2026-04-24T08:45:15Z`
2. `Jordanmuss99/dynamic-pterodactyl#12` @ `2026-04-24T09:02:15Z`
3. `Jordanmuss99/dynamic-pterodactyl#12` @ `2026-04-24T09:29:00Z`
4. `Jordanmuss99/dynamic-pterodactyl#12` @ `2026-04-24T09:52:11Z`
5. `ObsidianNetwork/Paymenter-Obsidian-Network#12` @ `2026-04-26T06:20:08Z`

All five bodies begin with:

```text
<!-- This is an auto-generated reply by CodeRabbit -->
<details>
<summary>✅ Actions performed</summary>

Review triggered.
```

Finding:
- `startswith("Actions performed:")` matches **0/5** current auto-acks.
- Current live format is an HTML `<summary>` wrapper containing `✅ Actions performed`.

Recommendation for Rule 8 filter:
- Filter on the current body shape, e.g. body contains `<summary>✅ Actions performed</summary>` or a looser `test("Actions performed")` guard.
- Do **not** rely on a plain-text `startswith("Actions performed:")` prefix.

## 3) CR nitpick threading behavior under CHILL

Primary sample:
- `Jordanmuss99/dynamic-pterodactyl#20`

Observed behavior:
- CodeRabbit posted nitpicks inside the formal review body under:

```text
<details>
<summary>🧹 Nitpick comments (2)</summary>
```

- The PR had zero review threads.
- Therefore the nitpicks were body-embedded in the review summary, not emitted as separate review threads.

Cross-checks:
- `#15` has explicit CR review threads with Jordan replies, but those are normal findings, not nitpick-tagged threads.
- `#19` has normal review threads as well; no evidence of thread-level nitpick tagging there either.

Conclusion:
- Under the current CHILL profile, the plan's nit-exclusion predicate is **dormant defense / guardrail**, not active-path logic.
- Rule 7 will usually never see nitpick-only findings because they are currently summary-embedded rather than threaded.
- Keep the thread-level nit exclusion anyway so the verifier remains correct if profile/CR behavior changes and nitpicks start arriving as threads.
