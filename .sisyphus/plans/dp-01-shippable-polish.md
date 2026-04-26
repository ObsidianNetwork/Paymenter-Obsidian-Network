# DynamicPterodactyl — Shippable Polish Bundle

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/` (nested git repo, branch `dynamic-slider`)
**Type**: Three small independent polish items bundled into one commit. Pure mechanical work, no design.

Bundles audit findings #1 (ExtensionMeta), #3 (route throttle), #4 (doc refresh + skeleton delete). No functional behaviour changes beyond the throttle and the missing-attribute bug fix.

---

## Why bundle these

Each item is a single-file or single-concept change, each under 30 lines. Running three separate PR/commit cycles is more churn than signal. One commit titled `chore: extension metadata, route throttling, doc sync` is readable and easy to revert if any piece is wrong.

---

## Change 1 — Add `#[ExtensionMeta]` attribute

### Target
`DynamicPterodactyl.php` — line 28, directly above `class DynamicPterodactyl extends Extension`.

### Read this first
- Root `AGENTS.md` reference: "Each extension class carries `#[App\Attributes\ExtensionMeta(name, description, version, author, url, icon)]`"
- Grep at least two working examples to confirm attribute parameter shape:
  - `extensions/Gateways/Stripe/Stripe.php`
  - `extensions/Others/Blog/Blog.php` (or any `Others/` extension)

### Values
```php
#[\App\Attributes\ExtensionMeta(
    name: 'Dynamic Pterodactyl',
    description: 'Dynamic resource sliders (RAM/CPU/Disk), real-time availability, and 15-min reservations for Pterodactyl products. Companion to the built-in Pterodactyl server extension.',
    version: '3.1.0',
    author: '<confirm via git log/CHANGELOG>',
    url: '<confirm or leave empty string>',
    icon: '<match existing Paymenter extensions — likely a heroicon name or asset path>',
)]
```

Before writing, confirm:
- The actual FQCN of the attribute (could be `App\Attributes\ExtensionMeta` or `Paymenter\Core\Attributes\ExtensionMeta` — grep one working extension).
- Whether the attribute accepts all six fields or only a subset (match the grep result).

### Add import if needed
If the working examples use a shortened form (`use App\Attributes\ExtensionMeta;` + `#[ExtensionMeta(...)]`), use that. Otherwise fully-qualified is fine.

---

## Change 2 — Rate limit public availability/pricing routes

### Target
`routes/api.php`

### Rationale
Public routes (availability, pricing) currently sit behind `['web', 'auth']` only. Any logged-in customer can hammer availability in a loop and burn the Pterodactyl 240/min budget for everyone.

### Change
Add `throttle:30,1` to the public route group. `30` requests/min per user is generous for real UI polling and tight enough to catch scripts.

- Read `routes/api.php` first.
- Identify the public route group (availability, pricing — NOT reservations; reservation writes are a different concern).
- Apply `->middleware('throttle:30,1')` at the group level, or add to the middleware array.

### Not in scope
- Admin routes already rate-limited via Filament's admin panel behaviour; don't double up.
- Reservation create/cancel: these are already gated by cart lifecycle events, not customer polling. Leave alone.

### Test
After change: `php artisan route:list --path=extensions/dynamic-pterodactyl` shows `throttle:30,1` in the middleware column for availability/pricing routes, not for reservation or admin routes.

---

## Change 3 — Documentation refresh + stale-artifact cleanup

All tasks below are additive or replace-only edits to markdown. No source code changes.

### 3a. `README.md`
- Replace the "File Structure" block with the current layout (no `Filament/`, no `Jobs/`, has `Admin/`, has `tests/bootstrap.php`).
- Remove `ptero_pricing_configs` from the "Database Tables" section.
- Bump version: `3.1.0` (match the ExtensionMeta change above).
- Update the "File Structure" block comment (first line says `extension/Others/DynamicPterodactyl/` — typo; actual is `extensions/`).

### 3b. `CHANGELOG.md`
- Move current `[Unreleased]` content under a new `## [3.1.0] — 2026-04-21` heading.
- Add entries for the reservation lifecycle fixes commit `6dddb68` and this polish bundle commit.

### 3c. `CLAUDE.md`
- Line 63 (or wherever it appears): fix `app/Extensions/Others/DynamicPterodactyl/` → `extensions/Others/DynamicPterodactyl/`.
- Grep for any other stale path references in the same file and fix in the same pass.

### 3d. Spec docs (`03-API.md`, `05-ADMIN-UI.md`)
- `03-API.md`: remove or mark-deprecated any endpoint referencing `ptero_pricing_configs`. Note that pricing now reads from native `ConfigOption` rows with `type='dynamic_slider'`.
- `05-ADMIN-UI.md`: replace separate "Pricing Configs Resource" and "Settings Page" sections with a single "Setup Wizard" section. Keep existing AlertConfig + Reservation resource sections.

### 3e. Delete `skeleton/`
- Safe per the extension's own `AGENTS.md`. Confirm no references anywhere:
  ```bash
  grep -rn "skeleton/" /var/www/paymenter/extensions/Others/DynamicPterodactyl/ --include='*.php'
  ```
  Should return nothing.
- `git rm -r skeleton/`

### 3f. `.gitignore` (nested repo root)
- Add standard Laravel/PHP entries if missing:
  ```
  vendor/
  .env
  .env.local
  .phpunit.result.cache
  .phpunit.cache/
  ```

### 3g. Extension's `AGENTS.md`
- Fix the phpunit bootstrap note — previous line said "bootstraps `../../../vendor/autoload.php`"; the build commit `6dddb68` introduced `tests/bootstrap.php`. Update the one-liner.

---

## Testing

No unit tests required. Verification is operational:

1. `php artisan extension:list` (or whatever Paymenter's CLI is — check) shows "Dynamic Pterodactyl" with version `3.1.0` and the description from ExtensionMeta. If the CLI doesn't exist, load the admin extensions page and check visually.
2. `php artisan route:list --path=extensions/dynamic-pterodactyl` shows throttle on availability + pricing, not on reservation or admin routes.
3. `ls extensions/Others/DynamicPterodactyl/skeleton` errors with "No such file or directory".
4. `grep -n "ptero_pricing_configs\|Filament/\|Jobs/" extensions/Others/DynamicPterodactyl/README.md` returns nothing.

---

## Commit

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git add -A
git commit -m "chore: extension metadata, route throttling, doc sync"
```

---

## Delegation

`task(category="quick", load_skills=[], run_in_background=true, ...)` — trivial mechanical work.

The agent should:
1. Read the three source files before editing (`DynamicPterodactyl.php`, `routes/api.php`, `README.md`) to get LINE#ID tags.
2. Apply the 7 edits across the listed files.
3. Run `git rm -r skeleton/`.
4. Commit from inside the nested extension repo with the exact message above.
5. Report `git log --oneline -1` as confirmation.

---

## Out of scope (separate plans)

- Pterodactyl API retry/timeout → `dp-02-pterodactyl-http-resilience.md`
- Audit log coverage → `dp-03-audit-log-coverage.md`
- Shortfall notifications → `dp-04-shortfall-notifications.md`
- Admin API routes → `dp-05-admin-api-routes.md`
- Pricing config validation → `dp-06-pricing-config-validation.md`


---

## Closeout (2026-04-26)

**Status: SHIPPED in two stages.**

**Stage 1 (2026-04-21)**: Changes 1-2 shipped via the original dp-01 commit:
- Change 1 (ExtensionMeta attribute on `DynamicPterodactyl` class): live at `DynamicPterodactyl.php:25`.
- Change 2 (route throttle, 30 req/min on availability + pricing endpoints): live at `routes/api.php` (`'throttle:30,1'` middleware).

**Stage 2 (2026-04-26)**: Change 3 (docs refresh + skeleton delete) shipped via the closeout plan `.sisyphus/plans/dp-01-doc-refresh-skeleton-delete.md`. The original Change 3 specification was rescoped because dp-07 (PR #6 in extension repo) had already done much of the doc cleanup, and dp-09 / dp-11 / dp-13 introduced new drift (e.g. `PricingCalculatorService` renamed to `SliderConfigReaderService`, migration count grew 5→7). The closeout plan captures the actually-needed mutations:
- Extension repo PR #16 — `chore(docs): refresh README + AGENTS for post-dp-13 reality; delete empty skeleton/`
- Squash SHA: `e2034a485cb76cf1607d8ab776f9c0406297e4df`
- CodeRabbit verdict: APPROVED (zero findings)
- Author: `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`

All three Changes complete. dp-01 is fully retired.