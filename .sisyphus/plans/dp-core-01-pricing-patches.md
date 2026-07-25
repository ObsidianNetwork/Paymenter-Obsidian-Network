# Paymenter Core — Dynamic Slider Pricing Patches

**Scope**: `/var/www/paymenter/` (the outer Paymenter fork — **not** the DynamicPterodactyl extension repo).
**Type**: Fix structural bugs in Paymenter core's native `dynamic_slider` config option type so the extension can retire its compensating pricing scaffolding.
**Delivery**: Commits to our Paymenter fork. Integration branch is `dynamic-slider/1.4.7` (verified via `git branch`). **Not** an upstream PR to `paymenter/paymenter`. Every patch here increases fork divergence; accept that cost deliberately.

---

## Problem

The dp-07 Phase 1 investigation (session `ses_24c139d79ffeXOiyEsd3b2c0xz`) established that Paymenter core carries five defects in its `dynamic_slider` handling. The DynamicPterodactyl extension was compensating for some of them (badly) and ignoring others. Until these are fixed in core, the extension cannot retire its `PricingCalculatorService`, and renewal billing for any dynamic-slider product is broken.

### Core defect 1 — per-slider base_price duplication
**Evidence:**
- `app/Models/ConfigOption.php:73-82` (`calculateLinearPrice`): starts from `pricing.base_price`, adds marginal rate.
- `app/Models/ConfigOption.php:88-111` (`calculateTieredPrice`): starts from `pricing.base_price`, adds tier charges.
- `app/Models/ConfigOption.php:117-129` (`calculateBaseAddonPrice`): starts from `pricing.base_price`, adds overage.
- `app/Livewire/Products/Checkout.php:102-137`: computes `plan->price()->price + sum(calculateDynamicPrice for each slider)`.
- `app/Models/CartItem.php:45-116`: same pattern as Checkout.

**Failure mode:** a product with memory+cpu+disk sliders and `base_price=5` on each charges `plan_price + 15`. Customer overpays by up to `N × base_price` where N = number of sliders.

### Core defect 2 — `base_plus_addon` falls through to linear
**Evidence:**
- `app/Models/ConfigOption.php:61-65`: match statement only handles `'tiered'` and `'base_addon'`. Anything else (including legacy `'base_plus_addon'`) hits the default branch and is priced as linear.

**Failure mode:** silent mispricing for any config that used the documented alias. dp-07 Phase 4 removed the alias from our extension's validator, but core still needs to reject unknown models explicitly rather than silently fall through.

### Core defect 3 — recalc paths ignore dynamic sliders
**Evidence:**
- `app/Livewire/Cart.php:192-216`: on checkout, dynamic-slider selections are stored as service **properties**, not as `configValue` rows.
- `app/Models/Service.php:207-235` (`Service::calculatePrice()`): iterates `service->configs` / `configValue` only. Dynamic-slider properties are invisible.
- `app/Console/Commands/CronJob.php:57-93`: renewal invoicing reuses persisted `service->price` unless a coupon forces a recalc, at which point it calls `Service::calculatePrice()` — which is blind to sliders.

**Failure mode:** on renewal with any pricing change (price increase, coupon applied, manual recalc), dynamic-slider products are re-invoiced at the wrong price or at zero. **This is the most dangerous bug of the five** because it fails silently on an automated cron path, not at interactive checkout.

### Core defect 4 — upgrade flow can't handle numeric sliders
**Evidence:**
- `app/Admin/Resources/ConfigOptionResource.php:69-70`: admin form exposes an "upgradable" toggle on dynamic-slider options.
- `app/Models/Service.php:181-185`, `app/Livewire/Services/Upgrade.php:60-74, 91-99, 120-127`, `app/Models/ServiceUpgrade.php:75-92`: upgrade logic is built around child option IDs, not numeric values. A slider upgrade cannot be represented or priced.

**Failure mode:** admin can tick "upgradable" on a slider, customer sees an upgrade affordance, upgrade attempt silently does nothing or breaks.

### Core defect 5 — no server-side validation of pricing schema
**Evidence:**
- `app/Admin/Resources/ConfigOptionResource.php:119-266`: form-level `required()`/`numeric()` only. No validation for:
  - Tier `up_to` values being strictly ascending
  - Required keys per pricing model
  - Unknown model rejection (ties to defect 2)
  - Shared-base semantics (ties to defect 1)

**Failure mode:** admin can save malformed pricing and customers see undefined behavior at checkout. Our extension ships `PricingConfigValidator` as a compensating layer; core should own this.

---

## Design

### Patch strategy — one targeted commit per defect

Five commits on a single branch. Each commit has a test. Reviewable independently but merged as one PR.

#### Patch 1 — Separate shared product base from per-slider marginal

**Files:** `app/Models/ConfigOption.php`, `app/Livewire/Products/Checkout.php`, `app/Models/CartItem.php`, new migration on `plans`

**Change:**
- Extract the base-price responsibility out of `calculateDynamicPrice()`. Rename it to `calculateDynamicPriceDelta()` (marginal only). Keep the old name as `@deprecated` alias for one release cycle to avoid breaking external callers.
- Add a new nullable column `plans.dynamic_slider_base_price` (decimal(10,2)) and a `Plan::dynamicSliderBasePrice()` accessor.
- `Checkout` and `CartItem` compute `plan->price + (plan->dynamic_slider_base_price ?? 0) + sum(slider_deltas)` instead of `plan->price + sum(full_slider_prices)`.
- Migration path: for any existing plan whose product has multiple sliders with the same `base_price`, collapse to one plan-level base and zero the per-slider copies. Write a one-time artisan command `paymenter:migrate-slider-base-price` that:
  - Supports `--dry-run` (default) printing proposed changes.
  - Requires `--force` to mutate.
  - Emits a CSV of `(product_id, plan_id, before_total, after_total)` for review.

**Test:**
- `tests/Unit/ConfigOptionDynamicPricingTest.php`: product with 3 sliders each `base_price=5` → total adds 5 (not 15) in addition to plan price.
- `tests/Feature/CartPricingTest.php`: checkout total matches cart total matches invoice total for a 3-slider product.

#### Patch 2 — Reject unknown pricing models explicitly

**Files:** `app/Models/ConfigOption.php`

**Change:**
- Replace the fall-through default in the `calculateDynamicPrice()` match statement with an explicit throw:
  ```php
  default => throw new \InvalidArgumentException("Unknown dynamic_slider pricing model: " . var_export($model, true)),
  ```
- This surfaces misconfiguration at checkout rather than silently mispricing. Paired with Patch 5's admin-side validation, the error is reached only if data was direct-DB-inserted or migrated in.

**Test:**
- `tests/Unit/ConfigOptionDynamicPricingTest.php`: unknown model throws with the offending name in the message. Legacy `base_plus_addon` throws (dp-07 Phase 4 has already removed it from the extension validator; this confirms core's defense-in-depth).

#### Patch 3 — Make recalculation paths slider-aware

**Files:** `app/Livewire/Cart.php`, `app/Models/Service.php`, `app/Models/ServiceUpgrade.php`, one migration, one artisan backfill command

**Change:** This is the largest patch. Approach **3a**:
- **Persist sliders as `configValue` rows too.** On checkout, write slider selections into `configValue` in addition to service properties. `Service::calculatePrice()` then sees them via its existing iteration. Minimal changes to recalc math.
- Ship a one-shot artisan command `paymenter:backfill-slider-config-values` that, for every active service with slider properties but no corresponding `configValue`, writes the missing rows. `--dry-run`/`--force` semantics match Patch 1.
- Keep property-store writing as-is for backward-compat reads. `configValue` is additive and becomes the pricing source.

**Rejected alternative 3b:** teach recalc to look at service properties. Two sources of truth for slider selection (properties and configs, diverging). Not worth the risk.

**Test:**
- `tests/Feature/ServiceRecalculationTest.php`: service with 3-slider config, trigger `calculatePrice()`, assert total matches original cart total.
- `tests/Feature/RenewalInvoiceTest.php`: end-to-end renewal cron for a slider product, invoice total matches.

#### Patch 4 — Exclude dynamic_slider from upgradable (first cut)

**Files:** `app/Admin/Resources/ConfigOptionResource.php`

**Change:**
- Hide the "upgradable" toggle when `type == 'dynamic_slider'`. Add helper text: "Dynamic sliders are not yet upgradable."
- Second cut (numeric-slider upgrade semantics) belongs in its own plan — out of scope here.

**Test:**
- `tests/Feature/Admin/ConfigOptionResourceTest.php`: upgradable toggle is not rendered for dynamic_slider options.

#### Patch 5 — Server-side pricing schema validation

**Files:** `app/Admin/Resources/ConfigOptionResource.php`, new `app/Rules/DynamicSliderPricingRule.php`

**Change:**
- Add a form-save validator that runs the equivalent of the extension's `PricingConfigValidator` at the core form level:
  - Required keys per model (`linear` → `rate_per_unit`; `tiered` → `tiers[]` with `up_to`+`rate`; `base_addon` → `included_units`+`overage_rate`).
  - Tier `up_to` values strictly ascending.
  - Non-negative rates and base prices.
  - Recognized model name (whitelist of `linear`, `tiered`, `base_addon`; anything else rejected).
- Once this ships, the extension's `PricingConfigValidator` becomes belt-and-suspenders at write-time and can be thinned in dp-09.

**Test:**
- `tests/Feature/Admin/ConfigOptionResourceTest.php`: submit invalid pricing (out-of-order tiers, unknown model, negative rate, missing required key) → form rejects with the expected error.

---

## Testing

Each patch ships with tests above. In addition:

### Regression suite
Run `vendor/bin/phpunit` from `/var/www/paymenter/` before and after. Baseline must be green pre-patch; additions must not regress.

### Manual smoke
1. Fresh checkout of a dynamic-slider product: cart total matches checkout total matches invoice total.
2. Renewal cron tick on an active slider service: generated invoice matches the slider's current price.
3. Admin SetupWizard (extension) submits a valid pricing config: no change in behavior.
4. Direct-DB insert of `base_plus_addon` pricing on a config option → checkout throws a clear error, doesn't silently undercharge.
5. Admin submits out-of-order tiers via the core form → form rejects before save.

### Performance check
Patch 1 adds one lookup per product during checkout/cart. Confirm no N+1 on cart pages with many slider products. Patch 3a's `configValue` writes are additive; confirm cart save remains O(slider_count).

---

## Risks

| Risk | Mitigation |
|---|---|
| Fork divergence from upstream makes future rebases painful | Each patch is one file-group, small, localized. Document the fork-only decision in a new repo-root `FORK-NOTES.md` listing each patch with SHA + rationale. |
| Patch 1's base-price migration loses data | Migration command is a one-shot, dry-run-first artisan command with CSV output. Production run is gated on a SQL count + manual approval. |
| Patch 3's dual-write (properties + configValue) diverges | Keep the property store writing as-is; `configValue` is the new pricing source. Add a read-time consistency assertion in `Service::calculatePrice()` that logs (not throws) if the two diverge, so drift is observable before it bites. |
| Patch 3 is large and touches cron-driven renewal code | Ship Patch 3 on its own final commit after Patches 1, 2, 4, 5 are merged and exercised. Pre-deploy: run `paymenter:preview-renewals --dry-run` against production data and diff against current invoice amounts. |
| Patch 2's throw surfaces at checkout for legacy data | dp-07 Phase 4 has already removed `base_plus_addon` from the extension validator and done a prod SQL check. Patch 5's server-side validation means no new invalid data can be saved. So Patch 2's throw should be unreachable in practice for us, but catches external/imported data. |
| Patch 4's hide-toggle approach could confuse admins who expected upgradable | Helper text on the hidden state explains why. If demand is real, second-cut implementation comes in its own plan. |
| CodeRabbit review surfaces core-specific concerns we don't know to anticipate | See the `/ralph-loop` section below. Budget 2–3 review rounds. |
| Tests in core repo may have different conventions than extension tests | Read one existing core feature test (e.g. `tests/Feature/CheckoutTest.php` if it exists, else the nearest analogue) before writing new ones; match the style. |

---

## Acceptance

- Five patches land as five commits on branch `dp-core-01-pricing-patches`.
- `vendor/bin/phpunit` green at `/var/www/paymenter/` (baseline count + new test count, no regressions).
- Manual smoke tests 1–5 above all pass.
- A fresh 3-slider product: cart total, checkout preview, stored service price, first invoice, and first renewal invoice all match and all include exactly one shared base price.
- Admin SetupWizard in the extension (which writes through core's `dynamic_slider` flow) continues to work unchanged.
- Extension's `PricingCalculatorService` can be deleted without changing customer-facing pricing behavior — verified by running the extension's phpunit suite after setting the service to a no-op (future dp-09 task, mentioned here as a forcing function).
- New `FORK-NOTES.md` at `/var/www/paymenter/FORK-NOTES.md` lists dp-core-01 patches with rationale and SHAs.
- Post-merge: `PROGRESS.md` in the extension repo marks dp-core-01 shipped with the squash SHA.

---

## Commit

One commit per patch. Ordering:

1. **Patch 5 first** — admin-side validation lands before Patch 2's runtime strictness so no new bad data enters the DB while Patch 2 is in flight.
2. **Patch 2 second** — strict runtime rejection.
3. **Patch 1 third** — base-price separation.
4. **Patch 4 fourth** — hide upgradable toggle.
5. **Patch 3 last** — recalc + renewal, the riskiest patch. The other four de-risk the testing surface first.

Commit messages (match the style detected in recent commits — `fix(scope): description`):

```bash
cd /var/www/paymenter
git fetch origin
git checkout -b dp-core-01-pricing-patches origin/dynamic-slider/1.4.7

# Patch 5
git commit -m "feat(admin): server-side validation of dynamic_slider pricing schema"

# Patch 2
git commit -m "fix(pricing): reject unknown dynamic_slider models instead of falling through to linear"

# Patch 1
git commit -m "fix(pricing): separate shared product base from per-slider marginal charges"

# Patch 4
git commit -m "fix(admin): hide upgradable toggle for dynamic_slider config options"

# Patch 3 (largest, ship last)
git commit -m "fix(pricing): make service recalculation and renewal invoicing slider-aware"

# Docs commit
git commit -m "docs: record dp-core-01 fork patches in FORK-NOTES.md and CHANGELOG"
```

Also update `CHANGELOG.md` (repo root) and create `FORK-NOTES.md`.

---

## Delegation

Category: `deep`. Single subagent runs all five patches sequentially on one branch. Reason: shared test fixtures, shared type changes (Patch 1's method rename propagates to Patch 3's recalc call sites; Patch 5's rule informs Patch 2's error message).

### Branch setup (orchestrator runs before delegating)

```bash
cd /var/www/paymenter
git fetch origin
git status                                               # confirm clean working tree
git checkout -b dp-core-01-pricing-patches origin/dynamic-slider/1.4.7
```

### Commit author

All commits must use the noreply form required by GitHub push protection:

```
Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>
```

Verify after each commit:

```bash
git log -1 --format='%an <%ae>'
```

### Agent procedure (in order)

1. Read each cited file end-to-end before editing — no blind edits on core.
2. Confirm branch target is `dp-core-01-pricing-patches` off `origin/dynamic-slider/1.4.7`.
3. Implement **Patch 5**. Run `vendor/bin/phpunit` from repo root. Commit.
4. Implement **Patch 2**. Run `phpunit`. Commit.
5. Implement **Patch 1**. Run `phpunit`. Commit.
6. Implement **Patch 4**. Run `phpunit`. Commit.
7. Implement **Patch 3**. Run `phpunit`. **Before pushing, orchestrator halts for user to run dry-run artisan command and review output for unexpected price deltas on existing services.** Commit.
8. Write `FORK-NOTES.md` and update `CHANGELOG.md`. Commit.
9. Push: `git push -u origin dp-core-01-pricing-patches`.
10. Open PR **against the fork's integration branch**:
    ```bash
    gh pr create --base dynamic-slider/1.4.7 \
      --title "fix(pricing): dp-core-01 dynamic_slider core patches" \
      --fill
    ```
11. Enter the `/ralph-loop` review cycle below. Do not mark the plan complete until the PR is merged and post-merge bookkeeping is done.

### Pre-Patch-3 gate (user-operated)

```bash
php artisan paymenter:preview-renewals --dry-run
# (command to be created as part of Patch 3's tooling, or equivalent ad-hoc SQL diff)
```

If the diff shows unexpected price changes on existing services, halt and investigate before merging.

### Orchestrator sequencing

- dp-core-01 starts AFTER dp-08 merges (done: `5a28acb` on `dynamic-slider`).
- dp-core-01 must merge BEFORE dp-09 (dp-09 cleans up extension pricing scaffolding; that's meaningless until core is fixed).
- Post-merge: update `PROGRESS.md` in the extension repo with the squash SHA.

---

## `/ralph-loop` — CodeRabbit review cycle

**Mandatory discipline:** after every push, every `@coderabbitai` mention, and during any CodeRabbit re-review in progress, the agent **WAITS** for CodeRabbit before taking the next review action. No racing ahead. No premature merge.

### Loop semantics

1. **After pushing to the PR branch (initial push or any subsequent fix push):**
   - Wait for CodeRabbit's review to appear. CodeRabbit typically posts within 2–10 minutes but can take longer.
   - Poll via `gh pr view <number> --json reviews,comments,statusCheckRollup` at a sensible cadence (every 60–120 seconds, not faster). Do not spam.
   - If CodeRabbit shows status `IN_PROGRESS` / re-review in flight, keep waiting — do **not** interact until it finishes and posts its reply.
   - Do not proceed until CodeRabbit has posted a review or comments for the latest commit SHA **and** any in-flight re-review has completed.

2. **Once CodeRabbit has responded:**
   - Read every new CodeRabbit comment. For each one:
     - **Relevant + correct for our codebase/design** → make the fix. Batch multiple fixes into logical commits. Push when done.
     - **Not relevant** → post a reply on the PR mentioning `@coderabbitai` with a clear, specific rejection explaining why (cite code, plan section, or locked decision). Do not silently ignore.
   - While fixing, also scan for issues CodeRabbit missed — if you find any, fix them in the same round and note them in the commit message.

3. **After each commit+push in response to CodeRabbit:**
   - Go back to step 1 (wait for CodeRabbit's re-review). CodeRabbit re-reviews automatically on new commits.
   - **If CodeRabbit is mid-re-review when you get to this step, WAIT for it to finish and post its reply before doing anything else.**

4. **After posting `@coderabbitai` rejection comments (without code changes):**
   - Wait for CodeRabbit's reply. CodeRabbit will either accept the reasoning or counter. Do not merge or proceed while a `@coderabbitai` mention is unanswered.
   - **If CodeRabbit starts a re-review in response, WAIT for that re-review to finish and reply.**

5. **Terminating condition — ALL of these MUST be true before merge:**
   - CodeRabbit's most recent review has no unresolved actionable comments (either addressed or rejection accepted by CodeRabbit).
   - **All PR checks are passing and NOT pending.** Poll `gh pr checks <number>`. If any check is `PENDING` or `IN_PROGRESS`, wait. If any is `FAILED`, fix it and go back to step 1.
   - No unreplied `@coderabbitai` mentions from the agent.
   - No CodeRabbit re-review is in flight.
   - You (the agent) are satisfied no further improvements are needed.

6. **Merge:**
   ```bash
   gh pr merge <number> --squash --delete-branch \
     --subject "fix(pricing): dp-core-01 dynamic_slider core patches (#N)"
   ```

7. **Post-merge cleanup (outer repo):**
   ```bash
   cd /var/www/paymenter
   git checkout dynamic-slider/1.4.7
   git fetch origin --prune
   git pull origin dynamic-slider/1.4.7
   ```

8. **Post-merge bookkeeping (extension repo):**
   ```bash
   cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
   # Update PROGRESS.md: mark dp-core-01 shipped with the squash SHA.
   # Commit as Jordanmuss99 and push to dynamic-slider.
   ```

### Hard rules for the loop

- **WAIT AFTER EVERY PUSH.** Do not poll CodeRabbit faster than once every 60 seconds. Do not proceed to any "next step" while CodeRabbit's review for the current SHA is pending.
- **WAIT AFTER EVERY `@coderabbitai` MENTION.** CodeRabbit replies to mentions; treat those replies as gating.
- **WAIT WHILE CODERABBIT IS RE-REVIEWING.** If CodeRabbit is re-reviewing (new review in progress, comments being posted in a thread, or status shows `IN_PROGRESS`), do NOT push additional commits, do NOT post more `@coderabbitai` mentions, do NOT merge. Wait for the re-review to finish and post its reply first. Only then act on the combined feedback.
- **ALL PR CHECKS MUST BE GREEN.** "Pending" is not green. "Failed" is not green. If CI is still running, wait. Do not merge on a yellow/pending CI.
- **NO SILENT REJECTIONS.** Every CodeRabbit suggestion you reject must have a PR comment explaining why, with `@coderabbitai` tagged.
- **NO SCOPE CREEP.** If CodeRabbit suggests something genuinely out of scope for dp-core-01, reject with a clear rationale and optionally note it as a candidate for a future plan.

### Failure modes and recovery

| Failure | Recovery |
|---|---|
| CodeRabbit never responds after 30 minutes and shows no `IN_PROGRESS` state | Post a gentle `@coderabbitai please review` ping on the PR. Continue waiting. |
| CodeRabbit is stuck `IN_PROGRESS` for >45 minutes | Keep waiting. Do not force-push or comment in the meantime — that resets the cycle. If it genuinely appears wedged after 60+ minutes, post a neutral `@coderabbitai any update?` and wait again. |
| CI fails | Read the failure, fix, commit, push, re-enter the wait-for-CodeRabbit loop. |
| CodeRabbit re-opens an issue you thought was resolved | Reassess honestly. Either implement the real fix or post a more thorough `@coderabbitai` rejection. |
| Merge conflicts with `dynamic-slider/1.4.7` | Rebase onto latest `origin/dynamic-slider/1.4.7`, run tests, force-push (`git push --force-with-lease`), wait for CodeRabbit re-review. |
| Core PHPUnit regressions appear in unrelated areas after rebase | Bisect locally before pushing. Do not mask failures. |

---

## Out of scope

- Numeric-slider upgrade implementation (second cut of Patch 4).
- Rewriting the `dynamic_slider` admin form UX.
- Adding new pricing models.
- Integer-cents / money-library migration (mentioned in audit as design criticism; belongs in its own plan).
- Upstream PR to `paymenter/paymenter` — user has decided this is fork-only for now.
- Extension-side changes — dp-09 handles scaffolding cleanup after this lands.
