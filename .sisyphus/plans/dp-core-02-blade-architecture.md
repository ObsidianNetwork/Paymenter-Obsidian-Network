# dp-core-02 — Blade Architecture: Extract dynamic_slider to shared partial

**Scope**: `/var/www/paymenter/` (Paymenter fork, branch `dynamic-slider/1.4.7`)
**Type**: Core refactor. Extract the duplicated `@case('dynamic_slider')` block into a shared Blade partial so both `themes/default` and `themes/obsidian` reference a single source of truth.

---

## Deferred from dp-10 (Apr 2026)

**Source**: CodeRabbit PR review thread on PR #3 (dp-10), nitpick on `themes/obsidian/views/components/form/configoption.blade.php` lines 79-366.

**Finding**: The `dynamic_slider` case in both theme `configoption.blade.php` files is now ~290 lines of identical Alpine JS + markup. Any future bug fix or a11y patch must be applied twice. CodeRabbit recommends extracting into a single reusable Blade partial (e.g., `resources/views/components/form/dynamic-slider.blade.php` or a theme-neutral location) and replacing both theme copies with `@include` or `<x-form.dynamic-slider>`.

**File**: `themes/obsidian/views/components/form/configoption.blade.php` lines 79-366 (also `themes/default/views/components/form/configoption.blade.php` same lines)

**Date**: 2026-04-23

---

## Problem

dp-09 retired the extension-side slider rendering. dp-10 added ARIA attrs, keyboard handlers, loading/error states, and touch-target CSS — all applied identically to both theme files. The test suite now covers both themes via `view()->file()` assertions, which will catch drift, but any future change still requires two identical edits.

## Proposed Design

1. Extract the `dynamic_slider` Alpine x-data block and its Blade markup into a shared component (path TBD — needs to be accessible from both themes without relying on the active theme setting).
2. Replace both `@case('dynamic_slider')` blocks with a single `<x-dynamic-slider :config="$config" :name="$name" :plan="$plan" :showPriceTag="$showPriceTag" />` or `@include` call.
3. The shared component must accept all PHP variables injected by the `@case('dynamic_slider')` @php block.
4. Tests: consolidate `DynamicSliderAccessibilityTest` to render the component once (since there's only one copy); keep the Obsidian smoke assertion or convert to a theme-switch test.

## Out of scope for dp-10

dp-10's scope was a11y attributes + keyboard + loading/error + touch target. Architecture refactoring is a separate concern and a separate reviewable PR. Expanding dp-10's scope would have made the PR significantly larger with no direct a11y benefit.

## Risks

- qirolab/laravel-themer view resolution: need to verify that a component outside `themes/*/views/` is reachable from both themes. May require registering a new view namespace or using `resources/views/components/`.
- If the component lives in `resources/views/`, it bypasses the theme override system — this is fine for `dynamic_slider` since both themes use identical markup.


---

## Deferred from dp-10 round 2 (Apr 2026)

### Checkbox null-safety
**Source**: CodeRabbit PR #3 round-2 review, `themes/obsidian/views/components/form/configoption.blade.php` lines 390-393.
**Finding**: `$config->children->first()->price(...)` called without null check on `children->first()`. Throws when a checkbox config option has no children. Pre-existing bug not introduced by dp-10.
**Fix**: Guard with `$config->children->first() && $config->children->first()->price(...)->available` before concatenating price string.
**Date**: 2026-04-23

### Duplicate :placeholder in x-form.input
**Source**: CodeRabbit PR #3 round-2 review, `themes/obsidian/views/components/form/configoption.blade.php` lines 386-388.
**Finding**: `x-form.input` has two `:placeholder` bindings (`$config->default ?? ''` and `$config->placeholder ?? ''`). Second overrides first; first should be `:value` if intent is to set initial value. Pre-existing bug not introduced by dp-10.
**Fix**: Replace first `:placeholder="$config->default ?? ''"` with `:value="$config->default ?? ''"`.
**Date**: 2026-04-23


---

## Status

- [x] Extract `@case('dynamic_slider')` into shared Blade partial (`resources/views/components/form/dynamic-slider.blade.php`)
- [x] Replace both theme `configoption.blade.php` switch blocks with `@include('components.form.dynamic-slider')`
- [x] Fix checkbox null-safety guard on `$config->children->first()` in both themes
- [x] Fix duplicate `:placeholder` on `x-form.input` (first binding → `:value`) in both themes
- [x] Slim `DynamicSliderAccessibilityTest` obsidian block to smoke assertion
- [x] CodeRabbit divisor-safety fix applied (`max(1, (int) ($metadata['display_divisor'] ?? 1024))`)

**Shipped**: 2026-04-24 as PR [#4](https://github.com/ObsidianNetwork/Paymenter-Obsidian-Network/pull/4) on branch `dp-core-02-blade-architecture` → `dynamic-slider/1.4.7`.
**Commits**: `ccea7e86` (refactor) + `7910e056` (CR divisor fix).
**CR verdict**: 1 actionable finding — applied. Review thread resolved. Silence-after-mention 7 min+: clean.
**Gate**: `bash .sisyphus/templates/ralph-loop-verify.sh 4` → PASS (all 6 preconditions satisfied).