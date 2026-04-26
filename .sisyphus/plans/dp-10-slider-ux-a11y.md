# dp-10 — Slider UX + Accessibility

**Scope**: `/var/www/paymenter/` (the Paymenter fork, branch `dynamic-slider/1.4.7`). Implementation target is **core** — `themes/{default,obsidian}/views/components/form/configoption.blade.php` plus supporting helpers in `app/Models/ConfigOption.php` and `app/Livewire/Products/Checkout.php`. The extension at `extensions/Others/DynamicPterodactyl/` only consumes the slider via cart event listeners and is not modified by this plan.
**Type**: Core UX/a11y patch series. Same shape as dp-core-01 (single PR on the fork against `dynamic-slider/1.4.7`).
**Delivery**: Single PR, atomic commit per concern, squash-merge.
**Backlog mapping**: This fulfils the "dp-10: slider UX + a11y" backlog item recorded in PROGRESS.md (extension) and in dp-07/dp-08/dp-09 out-of-scope sections. Despite the `dp-10` (not `dp-core-02`) name, the work lives in the fork because dp-07 already retired the extension-side slider rendering.

---

## Problem

The native `dynamic_slider` blade component (rendered in both `themes/default/views/components/form/configoption.blade.php` and the Obsidian theme copy) is the customer-facing surface every Paymenter checkout/upgrade flow shows for memory/CPU/disk pickers. The slider currently has the following gaps (verified by an inventory pass against the live blade and by the WAI-ARIA APG slider pattern):

1. **Bare a11y semantics.** Only `aria-label` is set on the range input. There is no `role="slider"`, no `aria-valuenow|min|max`, no `aria-valuetext`, and no `aria-labelledby`/`aria-describedby` linkage. Screen readers announce the raw integer (e.g., `8192`) instead of the user-facing value (`8 GB`). Source-of-truth: WAI-ARIA APG Slider (https://www.w3.org/WAI/ARIA/apg/patterns/slider/).
2. **No live region for value/price changes.** When the customer drags the handle, the price text re-renders but is not announced to assistive tech. The value text is also not announced.
3. **Incomplete keyboard semantics.** Native `<input type="range">` covers Arrow keys and (in some browsers) Home/End, but PageUp/PageDown for 10× jumps are not standardised across browsers, and the focus ring relies on browser defaults (no WCAG 2.4.13-compliant 3:1 contrast guarantee).
4. **No loading/error UI for the price preview.** The extension's `POST /api/dynamic-pterodactyl/pricing/calculate` can return 404 (no sliders configured), 422 (foreign plan_id / missing required field), 410 (retired sub-endpoint), or 500. The current blade shows none of these states; on failure the price text either freezes or vanishes silently.
5. **Marginal touch targets.** The native range thumb hits WCAG 2.5.8 minimum (24×24 CSS px) on most browsers but misses the comfort target (~44 CSS px) used by mobile platforms. Drag accuracy on small phones is poor.
6. **Livewire morph spam (the "Round 10" issue).** The extension's `PROGRESS.md` records a historical `Livewire.hook('morph.updating')` workaround. Core's current component uses `wire:ignore` on the slider DOM, but value writes still trigger Livewire's debounced (300 ms) morph cycle, which intermittently causes the price text to flicker on slow networks. We can confirm/deny this lives in core and either fix it or close it as not-reproducible.

---

## Design

Five concerns, five commits. Each commit must keep `php artisan test` green from the fork root and must not change the public API of `Plan`, `ConfigOption`, or `Service` (those are dp-core-01's contract — see DECISIONS.md § 1).

### Commit 1 — A11y baseline: ARIA + live region

**Files**:
- `themes/default/views/components/form/configoption.blade.php`
- `themes/obsidian/views/components/form/configoption.blade.php`
- `app/Models/ConfigOption.php` (new accessor `formatValueForScreenReader($value)` if `formatValueForDisplay` returns markup)

**Change**:
- On the `<input type="range">` element, set:
  - `role="slider"` (redundant for native range but harmless and explicit)
  - `aria-valuemin="{{ $option->getMetadata('min') }}"`
  - `aria-valuemax="{{ $option->getMetadata('max') }}"`
  - `:aria-valuenow="value"` (Alpine-bound)
  - `:aria-valuetext="formattedValue"` (Alpine-bound, computed as `formatValueForDisplay(value)` — the existing core helper)
  - `aria-labelledby="slider-label-{{ $option->id }}"` pointing at the visible label
  - `aria-describedby="slider-price-{{ $option->id }}"` pointing at the live price text
- Add an `<output>` element with `id="slider-price-{{ $option->id }}"`, `role="status"`, `aria-live="polite"`, `aria-atomic="true"` that mirrors the formatted price. The visible price text stays where it is — this is a separate sr-only mirror so the announcement isn't fired on every redraw.
- Add a `<span class="sr-only">` containing "Use arrow keys to adjust, Page Up/Down for larger steps, Home and End for minimum and maximum."

**Why first**: a11y attributes are pure DOM additions with zero runtime risk. Doing it first means every subsequent commit can rely on the labelling.

**Test additions**:
- `tests/Feature/DynamicSliderAccessibilityTest.php` (new): renders a checkout view with a `dynamic_slider` config option attached and asserts the rendered HTML contains `role="slider"`, `aria-valuemin`, `aria-valuemax`, `aria-valuenow`, `aria-valuetext`, `aria-labelledby`, and the live `<output>` region. ~80 lines using Laravel's `view()` helper or Pest browser tests if available.

### Commit 2 — Keyboard interactions + focus ring

**Files**:
- `themes/default/views/components/form/configoption.blade.php`
- `themes/obsidian/views/components/form/configoption.blade.php`
- `themes/default/views/themes/default.css` (or wherever the focus ring lives)

**Change**:
- Add Alpine `x-on:keydown` handlers for the slider:
  - `PageUp` / `PageDown` → `value += step * 10` / `value -= step * 10`, clamped to `[min, max]`
  - `Home` → `value = min`
  - `End` → `value = max`
  - Arrows are left to native handling (browsers already handle them per APG)
- Add a `:focus-visible` ring with `outline: 2px solid var(--ring); outline-offset: 2px;` ensuring at least 3:1 contrast against the surrounding background (WCAG 2.4.13 — Focus Appearance, https://www.w3.org/WAI/WCAG22/Understanding/focus-appearance-minimum.html). If the theme uses Tailwind, prefer `focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-primary` or equivalent.

**Why second**: builds on the labels added in commit 1 so screen readers can announce the new key-driven value changes correctly.

**Test additions**:
- Add a smoke test that asserts the focus-visible CSS class is present in the rendered markup. Keystroke behaviour is best covered by a Pest browser test if Paymenter has the Dusk/Pest browser plugin; if not, document the manual smoke step in `09-IMPLEMENTATION.md`.

### Commit 3 — Loading/error UI for the price preview

**Files**:
- `themes/default/views/components/form/configoption.blade.php`
- `themes/obsidian/views/components/form/configoption.blade.php`

**Change**:
- Wrap the price text in a state-aware container with three Alpine states: `idle`, `loading`, `error`.
- On slider change (debounced 300 ms — match the existing `wire:model.live.debounce` cadence), set `state = 'loading'`, render a small spinner or "Calculating…" text, then call the pricing fetcher. On success → `state = 'idle'` and update price; on HTTP 4xx → `state = 'error'` with the response's `message` field; on 5xx → `state = 'error'` with a generic "Pricing temporarily unavailable" message.
- The slider stays interactive in `error` state. The "Add to cart" / "Continue" CTA is not blocked — the server still computes the authoritative price at checkout (per dp-09's design: extension never recomputes; core's `Service::calculatePrice()` is the source of truth).
- Add a `<span class="sr-only" aria-live="assertive">` for error messages so they're announced.

**Why third**: depends on the live region from commit 1 to announce errors and on the keyboard handlers from commit 2 to keep the slider usable when pricing is broken.

**Test additions**:
- `tests/Feature/DynamicSliderPriceErrorStateTest.php` (new): a Livewire test that mocks the pricing endpoint to return 500 and asserts the rendered DOM transitions to the error state with the expected message. ~60 lines.

### Commit 4 — Touch target sizing

**Files**:
- `themes/default/views/components/form/configoption.blade.php` (CSS only)
- `themes/obsidian/views/components/form/configoption.blade.php` (CSS only)

**Change**:
- Bump the visual handle size to ≥ 24×24 px (WCAG 2.5.8 minimum, https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html).
- Add a transparent ::before pseudo-element on `input[type=range]::-webkit-slider-thumb` (and `-moz-range-thumb`) to extend the touch-active area to ~44 px without changing the visual size — same trick noUiSlider uses with `.noUi-touch-area`.
- Verify on a `min-width: 320px` viewport that the slider doesn't overflow.

**Why fourth**: pure CSS, isolated from semantic changes. Easy to revert if a designer disagrees.

**Test additions**:
- None automatic. Manual smoke step added to the test plan: drag the handle on a 320 px viewport with touch emulation in DevTools.

### Commit 5 — Docs

**Files**:
- `09-IMPLEMENTATION.md` (Paymenter core docs) — new "Slider accessibility checklist" section with the WCAG criteria covered.
- `DECISIONS.md` (Paymenter core, FORK-NOTES section if it exists) — add a `dp-10 (Apr 2026)` entry recording the a11y patch and the live-region/keyboard contract.
- Extension `CHANGELOG.md` and `PROGRESS.md`: cross-link entries noting that the extension consumes the new ARIA-rich slider but did not change.

**No code changes** — pure docs.

---

## Testing

- After each commit: `cd /var/www/paymenter && php artisan test`. Must stay green. The fork already has 105+ tests (per dp-core-01 PROGRESS entry).
- Final suite must include `DynamicSliderAccessibilityTest` and `DynamicSliderPriceErrorStateTest`.
- **Manual smoke (post-PR-merge, before announcing dp-10 done)**:
  1. Tab to the slider with keyboard only → focus ring is visible.
  2. Use ↑ / ↓ → value changes by `step`. Use PageUp / PageDown → value changes by `step × 10`. Use Home / End → value snaps to min/max.
  3. Open VoiceOver (macOS) or NVDA (Windows). Focus the slider. Confirm announced text is "Memory, slider, 8 GB, 1 GB to 64 GB" (or the equivalent in screen reader phrasing) — not "8192".
  4. Drag the slider while throttling network to "Slow 3G". Confirm "Calculating…" appears and the price updates after the debounce.
  5. Block the pricing API in DevTools → confirm error state shows and is announced (assertive live region).
  6. On a 320 px viewport with touch emulation, drag the handle by 1 step at a time. Confirm hit target is comfortable.

---

## Risks

| Risk | Mitigation |
|---|---|
| Theme override breakage (the Obsidian theme copy might drift from the default) | Both themes patched in the same commit. Add a comment block at the top of each component noting the dp-10 contract. |
| Screen reader phrasing varies between NVDA/JAWS/VoiceOver | Stick to APG-recommended attribute set — that's what all three implement consistently. Don't over-engineer with custom `aria-roledescription`. |
| `aria-valuetext` formatter throwing on edge values (NaN, Infinity, missing `formatValueForDisplay` return) | Rule out by guarding the Alpine accessor with `Number.isFinite()` and falling back to the raw `value`. |
| Livewire "morph" still flickers price text under load | Commit 1's `<output>` element is outside Livewire's morph scope (it's Alpine-driven). If flicker persists in the existing visible price text, file as a separate dp-10 follow-up rather than expanding scope mid-PR. |
| Pest browser tests not installed in the fork | Fall back to view-render assertions on the rendered HTML string. Don't add a new dev dependency in this PR — defer that to a separate plan if useful. |
| Conflict with a future Paymenter upstream change to `configoption.blade.php` | The fork already has a Paymenter-Obsidian-Network branch (`dynamic-slider/1.4.7`). Conflicts will be resolved during the next upstream merge per FORK-NOTES.md. |

---

## Acceptance

- All five commits land on branch `dp-10-slider-ux-a11y` and squash-merge as one PR.
- The customer-facing slider in both `default` and `obsidian` themes:
  - Exposes the full WAI-ARIA APG slider attribute set.
  - Announces value and price changes via `aria-live` regions.
  - Supports PageUp/Down/Home/End in addition to native arrow keys.
  - Has a `:focus-visible` ring with ≥ 3:1 contrast.
  - Shows `loading` and `error` states for the pricing preview.
  - Has touch-active area ≥ 44 px while keeping the visual size unchanged.
- New tests `DynamicSliderAccessibilityTest` and `DynamicSliderPriceErrorStateTest` are present and green.
- `php artisan test` from `/var/www/paymenter` is green.
- `09-IMPLEMENTATION.md` and `DECISIONS.md` updated.
- Extension `PROGRESS.md` records dp-10 shipped state with the squash SHA after merge.

---

## Commit sequence

```bash
cd /var/www/paymenter
git fetch origin
git checkout -b dp-10-slider-ux-a11y origin/dynamic-slider/1.4.7

# Commit 1
git commit -m "feat(slider): add WAI-ARIA APG attribute set + aria-live region (dp-10)"

# Commit 2
git commit -m "feat(slider): keyboard PageUp/Down/Home/End + WCAG 2.4.13 focus ring (dp-10)"

# Commit 3
git commit -m "feat(slider): loading/error UI for pricing preview, sr-only error live region (dp-10)"

# Commit 4
git commit -m "fix(slider): expand touch target to 44px while keeping visual handle size (dp-10)"

# Commit 5
git commit -m "docs(dp-10): a11y checklist in 09-IMPLEMENTATION; DECISIONS entry for slider contract"

git push -u origin dp-10-slider-ux-a11y
gh pr create --base dynamic-slider/1.4.7 --title "feat(slider): UX + a11y baseline (dp-10)" --fill
```

Author for every commit: `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`. Verify with `git log -1 --format='%an <%ae>'` after each commit.

---

## Process: Out-of-scope finding handling (NEW — applies to this and every future plan)

When implementing dp-10 (or any future plan), if the agent or CodeRabbit identifies a change that **does not fit the current PR's scope** — for example, a refactor that should land under a different dp number, an upstream Paymenter bug, an extension cleanup that belongs in a later wave, or a new dp item entirely — the agent MUST:

1. **Identify the correct destination plan**:
   - Is it slider UX/a11y polish? → stays in dp-10.
   - Is it auth/surface reduction? → drafted into `.sisyphus/plans/dp-11-…md`.
   - Is it observability? → drafted into `.sisyphus/plans/dp-12-…md`.
   - Is it Paymenter core? → drafted into `.sisyphus/plans/dp-core-NN-…md`.
   - Is it a brand-new concern? → create a new placeholder plan with name `.sisyphus/plans/dp-NN-shortname.md` and a stub describing the finding.
2. **Append the finding to that plan's "Deferred from <source-plan>" section** with:
   - One-paragraph description of the issue
   - File path + line number where the issue lives
   - Source citation (CodeRabbit thread URL, or "found during dp-10 commit N implementation")
   - Date
3. **Reply to the CodeRabbit thread** (if applicable) with: `@coderabbitai Acknowledged. This is out of scope for dp-10; deferred to dp-NN. See <link to plan>.` Then resolve the thread.
4. **Do NOT silently expand the current PR's scope** to include the deferred work. The whole point of the dp-NN cadence is small reviewable PRs.

This convention will be added to `DECISIONS.md` (extension) under a new "Process" section as part of dp-10 commit 5.

---

## /ralph-loop (verbatim contract)

> Use `/ralph-loop` to Review the pull request, read CodeRabbit's latest comments and decide if they are relevant to our codebase and design. If they are not, then mention CodeRabbit with `@coderabbitai` explaining why you are rejecting. If you agree with any comments, make the changes alongside any other issues you find in the review. When done, push the changes and **wait** for CodeRabbit to review the pull request again and post any new information. Then loop the above process until you and CodeRabbit are satisfied; when you are, merge the PR.
>
> When doing this the agent **HAS to wait** for CodeRabbit's review after a commit or when mentioning CodeRabbit. **All PR checks must be passed and not pending.** **If CodeRabbit is doing a re-review then you need to WAIT for it to finish and reply first.**

Operationalised:

| Trigger | Mandatory wait | Polling cadence |
|---|---|---|
| `git push` | wait for CodeRabbit incremental review (≈3–8 min) | poll every 60–90s |
| `@coderabbitai review` mention | wait for new `submittedAt` review timestamp newer than the mention | poll every 60–90s |
| CodeRabbit re-review IN_PROGRESS | wait until status leaves IN_PROGRESS (no commits, no mentions, no merges) | poll every 60s |
| Stuck >45 min with no activity | re-trigger with `@coderabbitai review`, then wait again | — |

Merge pre-conditions (ALL must hold simultaneously):
- `mergeStateStatus == "CLEAN"` and `mergeable == "MERGEABLE"`
- All status checks `SUCCESS` (no `PENDING`, no `FAILURE`, no missing rollup entries)
- `unresolved review threads == 0` (verify via the GraphQL `reviewThreads.isResolved` field)
- Last CodeRabbit review reports `Actionable comments posted: 0`, OR a verbal CodeRabbit confirmation that the latest commit is clean

Rejection protocol (CodeRabbit comment is not relevant):
- Post a single `@coderabbitai` reply explaining concretely why the comment doesn't apply (cite the file/line that already addresses it, the design decision that supersedes it, **or the dp-NN plan it has been deferred to per the process above**).
- Wait for CodeRabbit's response.
- Do not ignore comments silently; do not close threads without a reply.

Post-merge bookkeeping:
- `cd /var/www/paymenter && git checkout dynamic-slider/1.4.7 && git pull --ff-only`
- Append a `dp-10 shipped` row to the **extension's** `PROGRESS.md` with the fork's squash SHA from `gh pr view <n> --json mergeCommit`.
- Commit + push the extension's PROGRESS update on its `dynamic-slider` branch.
- Archive `.sisyphus/boulder.json` to `.sisyphus/completed/dp-10-slider-ux-a11y.boulder.json` and remove the active file.

---

## Out of scope

- Filament admin slider (the operator-facing config UI in `app/Admin/Resources/ConfigOptionResource/`). Has its own UX considerations; defer to a separate `dp-core-NN-admin-slider-ux.md` if needed.
- Switching from native `<input type=range>` to noUiSlider or Ariakit. The recon (see `.sisyphus/notepads/dp-10-slider-ux-a11y/learnings.md` once started) established that native + ARIA is the lowest-risk path; noUiSlider would add a JS dependency and Ariakit is React-only.
- Authorization/surface reduction (dp-11).
- Capacity-alert observability (dp-12).
- SetupWizard atomicity / E2E (dp-13).
- Any extension change. dp-10 is core-only.
- Touching `ptero_*` schema or pricing math (dp-core-01's territory).

---

## Delegation

Category: `deep`. One subagent runs all five commits sequentially on one branch.

Agent MUST:

1. Read each cited file end-to-end before editing — both theme copies (default + obsidian).
2. Confirm `git config user.email` is the noreply form before the first commit.
3. Implement Commit 1, run `php artisan test`, commit.
4. Implement Commit 2, run tests, commit.
5. Implement Commit 3, run tests, commit.
6. Implement Commit 4, run tests, commit.
7. Implement Commit 5 (docs only), run tests as a sanity check.
8. Push: `git push -u origin dp-10-slider-ux-a11y`.
9. Open PR against `dynamic-slider/1.4.7`.
10. Run the `/ralph-loop` block above until merged.
11. Apply the **out-of-scope handling process** to every CodeRabbit thread that proposes work outside the five commits above.
