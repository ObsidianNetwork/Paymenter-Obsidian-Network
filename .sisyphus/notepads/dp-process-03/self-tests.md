# dp-process-03 self-tests

Date: 2026-04-28

Command template used:

```bash
bash .sisyphus/templates/ralph-loop-verify.sh <PR> --repo Jordanmuss99/dynamic-pterodactyl --expected-base '^dynamic-slider' --dry-run
```

## PR #15 — regression baseline

Expected focus: Rule 7 PASS.

Observed stdout/stderr:

```text
PASS: Rule 0 — PR author = Jordanmuss99
PASS: Rule 1 — Base branch 'dynamic-slider' matches --expected-base '^dynamic-slider'
PASS: Rule 2 — All commit author emails = 164892154+Jordanmuss99@users.noreply.github.com
PASS: Rule 3 — CodeRabbit status=pass
FAIL: Rule 4 — mergeStateStatus=UNKNOWN (expected CLEAN) — CI checks pending/failing or branch out of date
PASS: Rule 5 — Zero unresolved review threads
PASS: Rule 6 — PR targets repo default 'dynamic-slider', which is also the expected integration branch
PASS: Rule 7 — Every non-nit CR-authored thread has a Jordanmuss99 reply
PASS: Rule 8 — Last CR activity at 2026-04-26T08:35:45Z is 150615s old (threshold 600s)
INFO: Rule 8 dry-run: no excluded pause/actions-performed comments observed
DRY-RUN: 1 rule(s) would fail for PR #15 on Jordanmuss99/dynamic-pterodactyl
```

Result:
- Rule 7 behaved as expected: PASS.
- Extra dry-run-only failure is Rule 4 because merged historical PRs now report `mergeStateStatus=UNKNOWN` instead of live-open-PR `CLEAN`.

## PR #18 — silent-thread baseline

Expected focus: Rule 7 FAIL.

Observed stdout/stderr:

```text
PASS: Rule 0 — PR author = Jordanmuss99
PASS: Rule 1 — Base branch 'dynamic-slider' matches --expected-base '^dynamic-slider'
PASS: Rule 2 — All commit author emails = 164892154+Jordanmuss99@users.noreply.github.com
PASS: Rule 3 — CodeRabbit status=pass
FAIL: Rule 4 — mergeStateStatus=UNKNOWN (expected CLEAN) — CI checks pending/failing or branch out of date
FAIL: Rule 5 — 2 unresolved review thread(s) on PR #18 — reply with reasoning or a fix commit, then resolve each thread
PASS: Rule 6 — PR targets repo default 'dynamic-slider', which is also the expected integration branch
FAIL: Rule 7 — CR thread(s) missing a Jordanmuss99 reply before resolution: PRRT_kwDOSIFgt859qrP8,PRRT_kwDOSIFgt859qrP9
PASS: Rule 8 — Last CR activity at 2026-04-26T15:15:09Z is 126651s old (threshold 600s)
INFO: Rule 8 dry-run: no excluded pause/actions-performed comments observed
DRY-RUN: 3 rule(s) would fail for PR #18 on Jordanmuss99/dynamic-pterodactyl
```

Result:
- Rule 7 behaved as expected: FAIL.
- Additional failures come from current historical PR state (Rule 4 = merged/closed `UNKNOWN`, Rule 5 = still-unresolved threads).

## PR #19 — sanity check

Plan expectation: should not false-positive.

Observed stdout/stderr:

```text
PASS: Rule 0 — PR author = Jordanmuss99
PASS: Rule 1 — Base branch 'dynamic-slider' matches --expected-base '^dynamic-slider'
PASS: Rule 2 — All commit author emails = 164892154+Jordanmuss99@users.noreply.github.com
PASS: Rule 3 — CodeRabbit status=pass
FAIL: Rule 4 — mergeStateStatus=UNKNOWN (expected CLEAN) — CI checks pending/failing or branch out of date
FAIL: Rule 5 — 1 unresolved review thread(s) on PR #19 — reply with reasoning or a fix commit, then resolve each thread
PASS: Rule 6 — PR targets repo default 'dynamic-slider', which is also the expected integration branch
FAIL: Rule 7 — CR thread(s) missing a Jordanmuss99 reply before resolution: PRRT_kwDOSIFgt859qvw0,PRRT_kwDOSIFgt859qvw- PRRT_kwDOSIFgt859qwgX
PASS: Rule 8 — Last CR activity at 2026-04-26T15:45:04Z is 124857s old (threshold 600s)
INFO: Rule 8 dry-run: no excluded pause/actions-performed comments observed
DRY-RUN: 3 rule(s) would fail for PR #19 on Jordanmuss99/dynamic-pterodactyl
```

Result / deviation:
- Live GitHub data does **not** match the plan's sanity expectation.
- PR #19 currently contains one unresolved CR thread and two resolved CR threads with no Jordan reply comments in-thread, so Rule 7 fails on the present thread graph.
- This appears to be a historical-data / plan-expectation mismatch, not a verifier false positive.

## PR #20 — dry-run summary capture

Expected focus: per-rule dry-run output.

Observed stdout/stderr:

```text
PASS: Rule 0 — PR author = Jordanmuss99
PASS: Rule 1 — Base branch 'dynamic-slider' matches --expected-base '^dynamic-slider'
PASS: Rule 2 — All commit author emails = 164892154+Jordanmuss99@users.noreply.github.com
PASS: Rule 3 — CodeRabbit status=pass
FAIL: Rule 4 — mergeStateStatus=UNKNOWN (expected CLEAN) — CI checks pending/failing or branch out of date
PASS: Rule 5 — Zero unresolved review threads
PASS: Rule 6 — PR targets repo default 'dynamic-slider', which is also the expected integration branch
PASS: Rule 7 — Every non-nit CR-authored thread has a Jordanmuss99 reply
PASS: Rule 8 — Last CR activity at 2026-04-26T16:07:57Z is 123483s old (threshold 600s)
INFO: Rule 8 dry-run: no excluded pause/actions-performed comments observed
DRY-RUN: 1 rule(s) would fail for PR #20 on Jordanmuss99/dynamic-pterodactyl
```

Result:
- Per-rule dry-run output is present as required.
- Only failure is the expected historical `mergeStateStatus=UNKNOWN` issue for a merged PR.

## Extra check — PR #12 for Rule 8 excluded-comment reporting

Purpose:
- Exercise the required dry-run diagnostic path that distinguishes excluded `Actions performed` comments.

Observed stdout/stderr:

```text
PASS: Rule 0 — PR author = Jordanmuss99
PASS: Rule 1 — Base branch 'dynamic-slider' matches --expected-base '^dynamic-slider'
PASS: Rule 2 — All commit author emails = 164892154+Jordanmuss99@users.noreply.github.com
PASS: Rule 3 — CodeRabbit status=pass
FAIL: Rule 4 — mergeStateStatus=UNKNOWN (expected CLEAN) — CI checks pending/failing or branch out of date
PASS: Rule 5 — Zero unresolved review threads
PASS: Rule 6 — PR targets repo default 'dynamic-slider', which is also the expected integration branch
FAIL: Rule 7 — CR thread(s) missing a Jordanmuss99 reply before resolution: PRRT_kwDOSIFgt859VRTj,PRRT_kwDOSIFgt859VgHq
PASS: Rule 8 — No post-last-commit CR activity detected; quiet period satisfied
INFO: Rule 8 dry-run: latest excluded comment = actions-performed@2026-04-24T11:19:34Z
DRY-RUN: 2 rule(s) would fail for PR #12 on Jordanmuss99/dynamic-pterodactyl
```

Result:
- `--dry-run` correctly reports the most recent excluded comment as an `actions-performed` auto-ack.
- This verifies the new Rule 8 debug output path on live GitHub data.
