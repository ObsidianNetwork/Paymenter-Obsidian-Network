# dp-core-01 — orchestrator status notepad

## Current state (2026-04-23)

- Branch: `dp-core-01-pricing-patches` created off `origin/dynamic-slider/1.4.7` (base: `264c173e`)
- Repo: `/var/www/paymenter/` (fork, NOT the extension)
- Plan: `/var/www/paymenter/.sisyphus/plans/dp-core-01-pricing-patches.md` (370 lines)
- Session: `ses_24a7ade8affeFcbNYEOugrZn08` (boulder.json)

## Blocking condition

Background task `bg_d9f14842` (session `ses_24a3fcea3ffe30Zi5s2svLRUNn`) is running.
System will notify on completion — do NOT call background_output until then.

## Patch order (subagent executing)

1. Patch 5 — server-side admin validation (DynamicSliderPricingRule.php + ConfigOptionResource.php)
2. Patch 2 — strict runtime reject in ConfigOption.php match
3. Patch 1 — base-price separation (plans migration, calculateDynamicPriceDelta, Checkout+CartItem update, migrate-slider-base-price artisan command)
4. Patch 4 — hide upgradable toggle for dynamic_slider
5. Patch 3 — recalc slider-awareness (Cart.php dual-write, Service::calculatePrice update, backfill command)
6. Docs — FORK-NOTES.md + CHANGELOG.md
7. Push + PR + /ralph-loop until merged

## USER GATE

Before committing Patch 3, subagent will halt and output dry-run results from
`paymenter:preview-renewals --dry-run`. User must approve before subagent commits and pushes.
When notification arrives, read background_output and check if gate output is present.

## TodoWrite registered (13 items, 1 completed)

1. [x] Branch created
2-6. [ ] Patches 5, 2, 1, 4, 3 (each with test + phpunit + commit)
7. [ ] Docs commit (FORK-NOTES.md + CHANGELOG.md)
8. [ ] Push + open PR
9. [ ] /ralph-loop CodeRabbit cycle
10. [ ] Squash-merge + update extension PROGRESS.md

## On wake

1. Call background_output(task_id="bg_d9f14842")
2. Check if USER GATE output is present — if so, review dry-run and approve or halt.
3. Otherwise verify: all 5 patch commits + docs commit + PR opened + merged.
4. Cross-check git log on dynamic-slider/1.4.7 for squash SHA.
5. Mark all todos complete.
6. Hand back to user with summary.

## Key file locations

- ConfigOption.php: `/var/www/paymenter/app/Models/ConfigOption.php`
- Checkout.php: `/var/www/paymenter/app/Livewire/Products/Checkout.php`
- CartItem.php: `/var/www/paymenter/app/Models/CartItem.php`
- Cart.php: `/var/www/paymenter/app/Livewire/Cart.php`
- Service.php: `/var/www/paymenter/app/Models/Service.php`
- CronJob.php: `/var/www/paymenter/app/Console/Commands/CronJob.php`
- ConfigOptionResource.php: `/var/www/paymenter/app/Admin/Resources/ConfigOptionResource.php`
- phpunit: `cd /var/www/paymenter && vendor/bin/phpunit`

## Hard rules

- Commit author: `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`
- All PR checks green (not pending) before merge
- WAIT after every push / @coderabbitai mention / CodeRabbit re-review
- No silent rejections on CodeRabbit comments
- PR target: `dynamic-slider/1.4.7` (NOT main, NOT master)
