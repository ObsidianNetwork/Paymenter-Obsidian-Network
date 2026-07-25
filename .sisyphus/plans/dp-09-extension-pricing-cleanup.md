# dp-09 — Extension Pricing Scaffolding Cleanup

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (the nested git repo on branch `dynamic-slider`).
**Type**: Retire compensating pricing scaffolding now that dp-core-01 (Paymenter fork PR #2, squash `121df289`) has shipped real authority for `dynamic_slider` pricing in core.
**Delivery**: Single PR on `dynamic-slider` against `dynamic-slider`. Atomic-ish commit per concern; squash-merge.
**Prerequisite (met)**: dp-core-01 merged 2026-04-23T00:00:35Z. Without that PR, this cleanup would create real customer-facing regressions.

---

## Problem

The extension shipped two compensating layers that were "load-bearing scaffolding" while Paymenter core's `dynamic_slider` had four structural defects (per `DECISIONS.md` § 1):

1. `Services/Validation/PricingConfigValidator.php` (114 lines) — write-time pricing-metadata validator. Now **100% subsumed** by core's `App\Rules\DynamicSliderPricingRule` (dp-core-01). The core rule additionally guards explicit nulls, non-numeric coercion, non-string model, empty-string `up_to`, and negative `up_to` — strictly stronger than the extension's version.
2. `Services/PricingCalculatorService.php` (223 lines) — alternate price computation path that pretends per-slider `base_price` is sane (dp-core-01 Patch 1 separated shared product base from per-slider marginal). It still calls the deprecated `ConfigOption::calculateDynamicPrice()` alias instead of `calculateDynamicPriceDelta()`, double-counts the base when more than one slider has `base_price > 0`, and ignores `Plan::dynamicSliderBasePrice()` entirely.

`PricingCalculatorService` is wired into:

- `Http/Controllers/Api/PricingController` (3 endpoints: `calculate`, `getConfig`, `validate`) — only two are routed; `validate` is orphaned.
- `Services/ReservationService` (constructor + line 94: `pricingService->calculate(...)` purely as an invalid-config guard before reservation create).
- Tests: `PricingCalculatorServiceTest`, `ReservationServiceTest`, `ReservationApiTest`, `SetupWizardValidationTest`.

`ConfigOptionSetupService::buildPricingMetadata` calls `pricingConfigValidator->validate($pricing)` directly. Core's form-level rule (wired via `ValidatesDynamicSliderPricing` trait + `->afterValidation()`) now catches the same shapes at write-time, so the extension's defensive call adds churn without value.

The end state we want: extension owns reservation lifecycle, availability, node selection, and slider config reading. Core owns pricing math and pricing-config validation. The extension never recomputes prices.

---

## Design

Five concerns, five commits. Order matters: reading layer first (least risky), then service deletions, then test/doc cleanup. Each commit must keep `phpunit.xml` green.

### Commit 1 — Replace `PricingCalculatorService::calculate()` with core delegation

**Files**:
- `Http/Controllers/Api/PricingController.php`
- `Services/PricingCalculatorService.php`
- `tests/Unit/PricingCalculatorServiceTest.php`

**Change**:
- `PricingController::calculate()` builds the same per-slider list but delegates math to `ConfigOption::calculateDynamicPriceDelta()` summed once with `Plan::dynamicSliderBasePrice()` (when at least one slider is in scope). Returns the same JSON shape (`{total, breakdown, model}`) so the frontend keeps working.
- `PricingCalculatorService::calculate()` is **deleted**. The controller no longer constructs the service for this endpoint.
- `tests/Unit/PricingCalculatorServiceTest.php` loses the `calculate()` cases; if the file ends up trivially small, fold remaining `getConfig()` tests into a renamed `SliderConfigReaderTest` (see Commit 2) or keep with the surviving method.

**Why first**: behavior swap. If the new path is wrong, the test that compares "cart total == checkout preview total" (added in Commit 1's tests) will catch it before any deletion makes it irreversible.

**Test additions**:
- `tests/Feature/PricingPreviewParityTest.php` (new, ~80 lines): given a 3-slider product with `plans.dynamic_slider_base_price = 5`, hit `POST /pricing/calculate` and assert `total == sum(deltas) + 5`. Cross-check against `Service::calculatePrice()` on the same inputs to prove parity with cart/renewal.

### Commit 2 — Slim `PricingCalculatorService` to `getConfig()` only and rename

**Files**:
- `Services/PricingCalculatorService.php` → **rename to** `Services/SliderConfigReaderService.php`
- `Http/Controllers/Api/PricingController.php`
- `Services/ReservationService.php`
- `tests/Unit/PricingCalculatorServiceTest.php` → rename to `SliderConfigReaderServiceTest.php`

**Change**:
- After Commit 1 the service has only `getConfig()` (slider definition reader for the frontend) and `validateResources()`. `validateResources()` is unreachable (no route) and duplicated by `StoreReservationRequest` (dp-08). Delete it.
- The remaining file is just `getConfig()` + the private `getDynamicSliderOptions()` helper. Rename to `SliderConfigReaderService` to reflect its actual job: reading slider definitions for the frontend. Move file accordingly. Update the controller's constructor.
- `ReservationService` no longer needs the price calculator (the line-94 guard is dropped in Commit 3). Remove the constructor parameter and the property.

**Test additions**: rename test file; keep existing `getConfig()` test cases; add one negative case verifying the renamed service still resolves through the container (`app(SliderConfigReaderService::class)`).

### Commit 3 — Drop `ReservationService` pricing guard

**Files**:
- `Services/ReservationService.php`
- `tests/Unit/ReservationServiceTest.php`
- `tests/Feature/ReservationApiTest.php`

**Change**:
- Remove the `$this->pricingService->calculate(...)` block at line 94 (the invalid-config guard) and the surrounding `try/catch` on `RuntimeException`.
- Rationale: the only failure mode that block caught was a malformed pricing config. Core's `DynamicSliderPricingRule` rejects malformed configs at write time (dp-core-01 Patch 5). `ConfigOption::calculateDynamicPriceDelta()` throws on unknown model (dp-core-01 Patch 2). So an invalid config can never reach the reservation path; if it somehow does, the throw happens at price calculation, not silently.
- Update `ReservationServiceTest`: remove the mocks/expectations on `PricingCalculatorService`, drop the test that asserted the invalid-config guard fires (replace with a regression test that an unknown-model dynamic slider still rejects at the resource-validation layer).

**Test additions**:
- `tests/Unit/ReservationServiceTest`: a new case proves create-reservation still works without any `PricingCalculatorService` injection (constructor signature change).

### Commit 4 — Delete `PricingConfigValidator`; thin `ConfigOptionSetupService`

**Files**:
- `Services/Validation/PricingConfigValidator.php` — **DELETE**
- `Services/Validation/` directory — empty after delete; remove
- `tests/Unit/PricingConfigValidatorTest.php` — **DELETE**
- `Services/ConfigOptionSetupService.php`
- `tests/Feature/SetupWizardValidationTest.php`

**Change**:
- Delete `PricingConfigValidator` and `InvalidPricingConfigException`.
- `ConfigOptionSetupService`: remove the `PricingConfigValidator $pricingConfigValidator` constructor parameter and the `$this->pricingConfigValidator->validate($pricing)` call inside `buildResourceMetadata()`. Pricing payloads built by `buildPricingMetadata()` are now persisted via `ConfigOption::create([...])`, which is intercepted by the same Filament form path on admin writes — but the wizard writes through Eloquent directly, NOT through Filament, so we lose the form-level validation hook. **Replacement**: instantiate `App\Rules\DynamicSliderPricingRule` and run it with a closure-collecting `$fail` (same pattern as `ValidatesDynamicSliderPricing` trait); throw `InvalidArgumentException` if errors collected. Keeps the wizard's "fail fast on bad pricing" semantics without owning the rule.
- `tests/Feature/SetupWizardValidationTest.php`: assertions stay valid (wizard still rejects malformed pricing); just verify the rejection messages now match `DynamicSliderPricingRule`'s wording, not the old extension validator's.

**Why bundled**: `ConfigOptionSetupService` is the only caller of `PricingConfigValidator` after Commits 1–3. Deleting the validator and re-wiring the service must happen in the same commit or `composer dump-autoload` ships a broken intermediate.

### Commit 5 — Docs + DECISIONS scaffolding-retired note

**Files**:
- `02-SERVICES.md` — drop `PricingCalculatorService` section; replace with single line referencing `SliderConfigReaderService`
- `03-API.md` — clarify `POST /pricing/calculate` now delegates to core; remove the orphan `POST /pricing/validate` row if it exists
- `07-PRICING-MODELS.md` — replace "interim scaffolding" callout with "pricing math owned by core (`Plan::dynamicSliderBasePrice` + `ConfigOption::calculateDynamicPriceDelta`)"
- `DECISIONS.md` — add a `dp-09 (Apr 2026)` revision under § 1 retiring the load-bearing-scaffolding note from dp-core-01
- `CHANGELOG.md` — entry for dp-09
- `PROGRESS.md` — bookkeeping (commit happens post-merge with the squash SHA)

**No test changes** — pure docs.

---

## Testing

- After each commit: `cd extensions/Others/DynamicPterodactyl && ../../../vendor/bin/phpunit --configuration phpunit.xml`. Must stay green.
- Final suite must include the new `PricingPreviewParityTest`.
- Manual smoke (post-PR-merge, before announcing dp-09 done):
  1. Setup Wizard creates a dynamic_slider product with valid pricing — succeeds.
  2. Setup Wizard with `model: "tiered"` and an out-of-order tier `up_to` — rejected, error message matches `DynamicSliderPricingRule` wording.
  3. Frontend price preview API on a 3-slider product with `plans.dynamic_slider_base_price=5` — total equals `5 + sum(per-slider deltas)`.
  4. Cart of that product → checkout → invoice → renewal: all four totals match.
  5. Reservation create with valid resources — succeeds.

---

## Risks

| Risk | Mitigation |
|---|---|
| Frontend breaks because pricing preview JSON shape changed | Keep the response shape identical — only the math source changes. Parity test in Commit 1 enforces the contract. |
| Wizard regresses because `PricingConfigValidator` had subtly different rules than `DynamicSliderPricingRule` | dp-core-01 round 5 explicitly added empty-string `up_to` rejection, which the extension validator already had — strictly aligned. Round 4 added negative-`up_to` rejection — the extension's `previousCap=0` start incidentally caught negative first-tier values too. Net: `DynamicSliderPricingRule` is strictly stronger. |
| `ReservationService` constructor change breaks consumers | The service is constructed via the container; signature change propagates. `Listeners/CartItemCreatedListener` already injects via `app(ReservationService::class)`. Verify with `grep -r "new ReservationService" extensions/`. |
| Container fails to resolve `SliderConfigReaderService` after rename | Auto-resolved (no binding); add a one-line test that calls `app(SliderConfigReaderService::class)`. |
| Merging this before users deploy dp-core-01 to prod | dp-core-01 is on `dynamic-slider/1.4.7`, the integration branch this extension already tracks. Same release window. |

---

## Acceptance

- All five commits land on branch `dp-09-extension-pricing-cleanup` and squash-merge as one PR.
- `Services/Validation/` directory removed; no file in the extension imports `PricingConfigValidator` or `InvalidPricingConfigException`.
- No file imports `PricingCalculatorService` (it no longer exists by that name; only `SliderConfigReaderService`).
- `Services/ReservationService` constructor takes 2 deps (`NodeSelectionService`, `AuditLogService`) instead of 3.
- `phpunit` green from extension dir.
- `PROGRESS.md` records the squash SHA after merge.

---

## Commit sequence

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git fetch origin
git checkout -b dp-09-extension-pricing-cleanup origin/dynamic-slider

# Commit 1
git commit -m "refactor(pricing): delegate /pricing/calculate to core (Plan + calculateDynamicPriceDelta)"

# Commit 2
git commit -m "refactor(pricing): rename PricingCalculatorService -> SliderConfigReaderService and drop dead validateResources"

# Commit 3
git commit -m "refactor(reservation): drop redundant pricing-config guard (core throws on unknown model)"

# Commit 4
git commit -m "refactor(pricing): retire PricingConfigValidator; wizard now uses core DynamicSliderPricingRule"

# Commit 5
git commit -m "docs(dp-09): retire scaffolding note in DECISIONS; update 02/03/07 + CHANGELOG"

git push -u origin dp-09-extension-pricing-cleanup
gh pr create --base dynamic-slider --title "refactor(pricing): retire extension pricing scaffolding (dp-09)" --fill
```

Author for every commit: `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`. Verify with `git log -1 --format='%an <%ae>'` after each commit; if the env got dropped, amend before pushing (GH push protection rejects the personal-noreply form too late if pushed).

---

## Delegation

Category: `deep`. One subagent runs all five commits sequentially on one branch. Reason: shared autoloader state — `composer dump-autoload` runs once at the end, so intermediate commits with deleted classes must compile.

Agent MUST:

1. Read each cited file end-to-end before editing.
2. Confirm `git config user.email` is the noreply form before the first commit.
3. Implement Commit 1, run phpunit, commit.
4. Implement Commit 2, run phpunit, commit.
5. Implement Commit 3, run phpunit, commit.
6. Implement Commit 4, run phpunit, **also run `composer dump-autoload`** to flush the deleted class — phpunit will FATAL if the deleted file is still in the classmap.
7. Implement Commit 5 (docs only), no phpunit needed but re-run anyway as a sanity check.
8. Push: `git push -u origin dp-09-extension-pricing-cleanup`.
9. Open PR against `dynamic-slider`.
10. Run the `/ralph-loop` block below until merged.

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
- `unresolved review threads == 0` (verify via the GraphQL `reviewThreads.isResolved` field, not just the comment count)
- Last CodeRabbit review reports `Actionable comments posted: 0`, OR a verbal CodeRabbit confirmation that the latest commit is clean (e.g., "Round-N fix is clean")

Rejection protocol (CodeRabbit comment is not relevant):
- Post a single `@coderabbitai` reply explaining concretely why the comment doesn't apply (cite the file/line that already addresses it, or the design decision that supersedes it).
- Wait for CodeRabbit's response (it usually posts "Comments resolved" within ~30s of an ack reply).
- Do not ignore comments silently; do not close threads without a reply.

Post-merge bookkeeping (this orchestrator owns it after the subagent's PR is merged):
- `git checkout dynamic-slider && git pull --ff-only`
- Append a `dp-09 shipped` row to `PROGRESS.md` with the squash SHA from `gh pr view <n> --json mergeCommit`.
- Commit + push.
- Archive `.sisyphus/boulder.json` to `.sisyphus/completed/dp-09-extension-pricing-cleanup.boulder.json` and remove the active file.

---

## Out of scope

- Extension UX or a11y changes (dp-10).
- Authorization/surface reduction (dp-11).
- Capacity-alert observability (dp-12).
- SetupWizard atomicity / E2E (dp-13).
- Any change to Paymenter core (that's dp-core-NN territory).
- Switching pricing math to integer-cents / a money library (separate plan).
- Touching the `ptero_*` schema.
