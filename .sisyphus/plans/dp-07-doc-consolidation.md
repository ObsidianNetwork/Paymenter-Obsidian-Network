# DynamicPterodactyl — Doc Consolidation + Decision Narrowing

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (docs + one migration + one validator) plus one read-only pass over `/var/www/paymenter/app/` for Phase 1.
**Type**: Lock pending design decisions into `DECISIONS.md`, rewrite drifted docs, and retire dead enum members — so subsequent plans (dp-08 onward) build on ground that matches reality.

---

## Problem

The full audit (session `ses_24c9dae2dffeSpxiEGlGCd5B4C` + `ses_24c9d4dd8ffecFFWJ2Uf4wI0Eb`) found significant drift between the extension's documentation, its schema, its validator, and its running code. Shipping more code on top of this drift compounds the confusion. Concrete drift:

1. **Pricing ownership is ambiguous.** `DECISIONS.md:74-84` says pricing moved to Paymenter core's native `dynamic_slider`. `README.md:30-36` still advertises the extension as owning a pricing calculator. `Services/PricingCalculatorService.php` is still the canonical pricing path at runtime. One of these three has to give.
2. **Addon pricing model has three names for one thing.** Docs (`07-PRICING-MODELS.md`) use `base_plus_addon`. SetupWizard (`Admin/Pages/SetupWizard.php:117-121`) emits `base_addon`. Validator (`Services/Validation/PricingConfigValidator.php:12-19`) accepts both. Runtime (`app/Models/ConfigOption.php:61-65`) handles only `base_addon`. Configs using the documented canonical name are silently mispriced as linear.
3. **`released` is a dead reservation state.** Schema (`database/migrations/2025_01_01_000001_create_ptero_resource_reservations_table.php:40-46`) and docs (`01-DATABASE.md:135-145`) both include it. No service method sets it (`Services/ReservationService.php:53-369`).
4. **Per-node availability route is customer-visible but docs claim admin-only.** `routes/api.php:18-23` vs `03-API.md:98-100`.
5. **Frontend docs describe a `noUiSlider` injection architecture that was replaced.** The actual slider is an Alpine.js native-range-input component in `themes/default/views/components/form/configoption.blade.php:79-248`. `06-FRONTEND.md` is entirely stale.
6. **Audit Log documents a `view_changes` JSON-diff modal.** `Admin/Pages/AuditLogPage.php:77` explicitly disables record actions with `->recordAction(null)`. The feature is missing. We either re-enable and ship it, or delete the doc.
7. **SetupWizard feature test is a skipped placeholder.** `tests/Feature/SetupWizardValidationTest.php:17-21`. Doc promises end-to-end coverage that doesn't exist.

Failure mode: anyone reading the docs to onboard or to make a change is misled. Future plans built on the docs inherit the drift.

The user has locked answers to all open design questions (see **Phase 2** for the full list). This plan records those answers, rewrites the docs to match, and retires the code debris around them.

---

## Design

### Four phases, gated

Each phase depends on the previous. No skipping.

- **Phase 1 — Pricing capability discovery** *(complete; outcome D — patch core)*
  Task `bg_53c42425` / session `ses_24c139d79ffeXOiyEsd3b2c0xz` concluded that Paymenter core carries four structural pricing defects: per-slider base-price duplication in `Checkout`/`CartItem`, `base_plus_addon` falling through to linear in `ConfigOption::calculateDynamicPrice`, recalc paths blind to dynamic sliders in `Service::calculatePrice`, and upgrade flow incompatible with numeric sliders. Fixes belong to core; the extension's `PricingCalculatorService` stays as load-bearing scaffolding until `dp-core-01-pricing-patches` lands. See `/var/www/paymenter/.sisyphus/plans/dp-core-01-pricing-patches.md`.

- **Phase 2 — Decision narrowing**
  Append a new section to `DECISIONS.md` codifying the locked answers. See full list below.

- **Phase 3 — Doc rewrites**
  Rewrite the drifted doc files against the narrowed decisions. No behavior changes, only text.

- **Phase 4 — Minor code cleanup to match narrowed decisions**
  - Migration to drop `released` from the `ptero_resource_reservations.status` enum (or equivalent if column is string-backed).
  - Remove `base_plus_addon` from `PricingConfigValidator`'s accepted model list; update tests; ensure no production data uses it (grep + production check).
  - Fix `03-API.md` / `routes/api.php` mismatch: **move** `/availability/{locationId}/nodes` into the admin-only group (Phase 3 documents; Phase 4 enforces).

Phases 2–4 all happen on one branch `dp-07-doc-consolidation` with sensible commits per phase.

### Locked decisions (what Phase 2 records)

These are the user's locked answers. Phase 2 transcribes them verbatim into `DECISIONS.md` with a dated heading.

1. **Pricing ownership direction.** Paymenter core is the intended pricing authority for `dynamic_slider`. Phase 1 concluded core carries four structural defects (per-slider base-price duplication in `Checkout`/`CartItem`, `base_plus_addon` falling through to linear in `ConfigOption::calculateDynamicPrice`, recalc paths blind to dynamic sliders in `Service::calculatePrice`, and upgrade flow incompatible with numeric sliders). Those fixes land on our Paymenter fork via **`dp-core-01-pricing-patches`** — fork-only, not upstream. Until dp-core-01 merges, the extension's `PricingCalculatorService` and `ConfigOptionSetupService::buildPricingMetadata` are **load-bearing scaffolding** that compensate for the core gaps. DECISIONS.md records this split with the core gaps enumerated so future readers know why both layers exist.
2. **Canonical addon model name.** `base_addon`. `base_plus_addon` is retired. Docs, validator, and wizard all use `base_addon`. Phase 4 removes the alias from the validator.
3. **`released` reservation state.** Deleted. Confirmed + expired + cancelled cover the observable lifecycle. If a post-confirm provisioning failure state is needed later, introduce `provision_failed` — a concrete meaning — not `released`.
4. **Per-node capacity exposure.** Admin-only. Customers never see raw node-level capacity. The customer signal for "this location is near capacity" is the slider clamping to the real allocatable max. Phase 4 moves `/availability/{locationId}/nodes` into the admin middleware group. The customer-facing slider max comes from the location-summary endpoint (already authenticated), which returns only aggregate per-location maxima — no node names, FQDNs, or maintenance flags.
5. **SetupWizard feature-test shipped status.** Unit coverage is accepted for dp-06. The full Filament-action lifecycle E2E test is deferred to **dp-13** (SetupWizard atomicity + audit-log reliability) since that plan touches `ConfigOptionSetupService` anyway. The skipped placeholder stays in tree with a `// TODO dp-13` marker; `tests/Feature/SetupWizardValidationTest.php:17-21` gets that comment.

### What Phase 3 rewrites

| File | Nature of rewrite |
|---|---|
| `README.md` | Rewrite responsibilities list: core is the intended pricing authority; extension provides interim pricing scaffolding pending dp-core-01. Update frontend section to reflect Alpine.js native slider. |
| `DECISIONS.md` | Append "Decisions locked 2026-04-22" section (Phase 2 output). Existing decisions stay as historical record. |
| `01-DATABASE.md` | Remove `released` from reservation state diagrams and tables. Lifecycle becomes pending → {confirmed, expired, cancelled}. |
| `03-API.md` | `/availability/{locationId}/nodes` moves from public to admin section. Remove any "admin-level detail" annotations from the public section. |
| `05-ADMIN-UI.md` | Remove the `view_changes` modal promise per locked decision (user-confirmed 2026-04-22). Audit-log reads stay read-only until a concrete need justifies re-enabling record actions. |
| `06-FRONTEND.md` | Full rewrite. Delete `noUiSlider` content. Document the Alpine.js component in `themes/default/views/components/form/configoption.blade.php`: data shape, price calculation locality (client-side), Livewire entanglement, a11y state (call out what's missing — `aria-live` etc. — as tracked in dp-11 slider UX plan). |
| `07-PRICING-MODELS.md` | Rewrite intro: pricing flows through core, extension provides scaffolding. Add a "Core gaps and interim responsibilities" section enumerating the four dp-core-01 defects with evidence citations. Replace all `base_plus_addon` references with `base_addon`. Warn that per-slider `base_price` is duplicated by core's summing logic today and will be corrected in dp-core-01 Patch 1. |
| `09-IMPLEMENTATION.md` | Reconcile with actual ship state: call out tests that are really feature tests vs unit, call out missing scheduler wiring for capacity alerts (cross-ref dp-13), remove "concurrent-reservation integration tests" claim unless they actually exist. |
| `PROGRESS.md` | Append dp-06 (shipped), dp-07 (in-progress), dp-core-01 (drafted, queued post-dp-07). |
| `CHANGELOG.md` | One entry covering Phase 4 code changes when they land. |
| `tests/Feature/SetupWizardValidationTest.php:17-21` | Add `// TODO dp-13:` marker per decision #5. Not a rewrite; one-line annotation. |

### What Phase 4 changes in code

Small and surgical. No behavior changes beyond the enum/alias removal.

- **Migration `database/migrations/<ts>_drop_released_from_reservation_status.php`.**
  - MySQL/Postgres enum drop pattern: since the column is likely `string` with app-level enum validation (common in Paymenter), first inspect the actual column type. If truly a DB enum, use `DB::statement('ALTER TABLE ...')`. If a string column with PHP-level enum, no DB change — just update the PHP enum or validation set. Down migration re-adds `released` for rollback safety.
  - Data check: `SELECT COUNT(*) FROM ptero_resource_reservations WHERE status = 'released';`. Expected 0 (no service sets it). If non-zero, update rows to `cancelled` before dropping (down migration restores mapping from a backup column or logs only).

- **`Services/Validation/PricingConfigValidator.php`.**
  - Remove `'base_plus_addon'` from accepted-model set (`:12-19`).
  - Update `tests/Unit/PricingConfigValidatorTest.php` to remove data-provider rows for `base_plus_addon` and add a row asserting `base_plus_addon` is now rejected as unknown.
  - Add a one-time `grep` across migrations + seeders + fixtures to confirm no test data uses `base_plus_addon`.
  - Before pushing: spot-check production DB with `SELECT ... FROM config_options WHERE settings->>'model' = 'base_plus_addon';` (user runs this; if hits > 0, Phase 4 also writes a data-migration to rewrite them).

- **`routes/api.php:18-23`.**
  - Move `/availability/{locationId}/nodes` route into the admin middleware group alongside the admin routes at `:33-40`.
  - Confirm `AvailabilityController::nodes` action has no assumption of customer caller.
  - Regression test: `tests/Feature/AdminApiTest.php` gains a 403-for-customer + 200-for-admin pair on `/availability/{id}/nodes`.

### What this plan does NOT do

- Does not fix reservation verification subtraction bug (dp-08).
- Does not fix base-price duplication across sliders — that lives in core and belongs to `dp-core-01` Patch 1.
- Does not fix the slider Livewire spam (dp-10).
- Does not restore the Audit Log `view_changes` modal. Explicitly removed from docs instead.
- Does not ship new pricing models.

---

## Testing

### Phase 4 only — Phases 1-3 have no code under test.

#### Unit
- Extend `tests/Unit/PricingConfigValidatorTest.php`:
  - Remove `base_plus_addon` happy-path rows from data provider.
  - Add one row: `base_plus_addon` config → rejected with "Unknown pricing model" message.
- If `tests/Unit/ReservationServiceTest.php` or similar references `released` anywhere, update to expected new enum set.

#### Feature
- Extend `tests/Feature/AdminApiTest.php`:
  - `test_customer_cannot_read_per_node_availability` → 403 on `/availability/{id}/nodes`.
  - `test_admin_can_read_per_node_availability` → 200, asserts expected shape.

#### Manual
1. Run migration in a scratch DB, confirm no broken status values remain; run down migration, confirm schema round-trips.
2. Submit SetupWizard with `{"model":"base_plus_addon", ...}` → Filament rejects with "Unknown pricing model".
3. Hit `/availability/1/nodes` as a customer → 403. Hit as an admin → 200.

---

## Risks

| Risk | Mitigation |
|---|---|
| Phase 1 reached outcome D; dp-core-01 now exists as a sibling plan | Already scoped as `dp-core-01-pricing-patches`. Phase 3 docs point at it by name. Phase 4 omits runtime-alignment bullet (core's job). Extension's compensating scaffolding stays in place until dp-core-01 merges. No blockers for dp-08. |
| Production DB contains `base_plus_addon` config rows we don't know about | Pre-Phase-4 gate: user runs `SELECT` check (plan states this explicitly). If non-zero, data-migration gets added to Phase 4 scope. |
| Production DB contains `released` reservation rows | Pre-Phase-4 gate: same pattern. Expected 0; migrate to `cancelled` if not. |
| Moving `/nodes` to admin breaks an existing frontend caller | Grep theme + `resources/` for `/availability/` consumers; confirm only admin pages use the `/nodes` variant. If a customer page depends on it, rework to use the aggregated location endpoint instead. |
| Doc rewrite conflicts with in-flight plans | No in-flight plans — dp-06 is merged, dp-08 onward don't exist yet. |
| CodeRabbit flags the enum migration for missing reverse-migration data safety | Down migration re-adds enum member; any rows that were rewritten during up-migration are logged so they can be inspected but not auto-restored. State this in PR description. |

---

## Acceptance

- `DECISIONS.md` has a new dated section recording all 5 locked answers with rationale.
- All doc files listed in the Phase 3 table match the decided state — no references to `base_plus_addon`, `released`, `noUiSlider`, `view_changes` modal, or customer-accessible per-node availability.
- Migration exists and is reversible; production data-safety check is documented in PR description.
- `PricingConfigValidator` rejects `base_plus_addon`; validator tests cover the rejection.
- `routes/api.php` has `/availability/{id}/nodes` under admin middleware; feature tests cover both 403 and 200 paths.
- `phpunit` green from inside `extensions/Others/DynamicPterodactyl/`.
- No functional regressions in admin dashboard or customer checkout (manual smoke).

---

## Commit

One commit per phase for easy review:

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl

# Phase 2
git add DECISIONS.md
git commit -m "docs(decisions): lock pricing/release/addon/per-node/wizard-test answers"

# Phase 3
git add README.md 01-DATABASE.md 03-API.md 05-ADMIN-UI.md 06-FRONTEND.md 07-PRICING-MODELS.md 09-IMPLEMENTATION.md PROGRESS.md tests/Feature/SetupWizardValidationTest.php
git commit -m "docs: rewrite drifted docs to match narrowed decisions"

# Phase 4
git add database/migrations/*drop_released* Services/Validation/PricingConfigValidator.php tests/Unit/PricingConfigValidatorTest.php routes/api.php tests/Feature/AdminApiTest.php CHANGELOG.md
git commit -m "chore(schema,api): retire released state, drop base_plus_addon alias, admin-gate per-node availability"
```

---

## Delegation

### Phase 1 — complete
Task `bg_53c42425` / session `ses_24c139d79ffeXOiyEsd3b2c0xz`. Outcome D (patch core). Sibling plan `dp-core-01-pricing-patches.md` drafted. No further Phase 1 work in this plan.

### Phases 2–4
Category: `deep`. Single subagent executes all three sequentially on one branch. Reason: Phase 3 references Phase 2's new `DECISIONS.md` section; Phase 4 references Phase 3's rewrites. Splitting across agents forces extra reads.

Branch setup (run before delegating):

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git fetch origin
git checkout -b dp-07-doc-consolidation origin/dynamic-slider
```

Agent MUST, in order:

1. Read Phase 1 outcome from this plan's Locked decisions §1 and the sibling `dp-core-01-pricing-patches.md`. No wait needed — Phase 1 is complete.
2. **Phase 2**: append the locked-decisions section to `DECISIONS.md`, dated `2026-04-22`. Exact wording of each decision comes from this plan's "Locked decisions" subsection verbatim; Phase 1 outcome fills in the pricing decision's conditional branch.
3. Commit Phase 2.
4. **Phase 3**: rewrite each doc file per the Phase 3 table. For each file, read current content before editing; preserve any section or detail not explicitly called out as stale. Do not reformat untouched sections.
5. For `05-ADMIN-UI.md`: remove `view_changes` modal content per locked decision (user-confirmed 2026-04-22; no further gate).
6. Commit Phase 3.
7. **Phase 4**:
   a. Inspect `database/migrations/2025_01_01_000001_create_ptero_resource_reservations_table.php` to determine whether `status` is a DB enum or a string column with PHP-level enum.
   b. User gate: `SELECT COUNT(*) FROM ptero_resource_reservations WHERE status='released';` and `SELECT COUNT(*) FROM config_options WHERE settings->>'model' = 'base_plus_addon';`. Agent requests user to run these and paste results before migration is generated.
   c. Generate migration matching the actual column type. Reversible.
   d. Remove `base_plus_addon` from `PricingConfigValidator`; update tests.
   e. Move `/availability/{id}/nodes` route; add feature tests.
   f. Add `// TODO dp-13:` annotation to `tests/Feature/SetupWizardValidationTest.php:17-21`.
   g. Run `../../../vendor/bin/phpunit --configuration phpunit.xml` from the extension dir.
8. Commit Phase 4.
9. Push and open PR:

```bash
git push -u origin dp-07-doc-consolidation
gh pr create --base dynamic-slider --title "docs+chore: consolidate docs, narrow decisions, retire dead state/alias" --fill
```

10. Run the standard CodeRabbit review loop per `/loop` semantics until merged.

Orchestrator MUST:
- Renumber the backlog after merge: audit's dp-07 (reservation correctness) becomes dp-08; every subsequent plan shifts by one.
- Update `PROGRESS.md` post-merge with final dp-07 status.

---

## Out of scope

- Paymenter core pricing patches — scoped as sibling plan `dp-core-01-pricing-patches.md` (already drafted). Runs after dp-07 merges.
- Restoring or re-implementing the Audit Log `view_changes` modal.
- Any visible UI behavior changes (slider, dashboard, wizard layout).
- Authentication / authorization reform beyond the one `/nodes` route move.
- Reservation verification correctness (dp-08).
- Pricing calculator correctness (dp-09).
- Slider network-spam fix (dp-10).
- Capacity alert scheduling and email delivery (dp-12).
- SetupWizard atomicity + audit-log reliability (dp-13).
