# dp-11 learnings (2026-04-23) — UPDATED

## CRITICAL: artisan --env= does not switch DB connections

`php artisan migrate:fresh --env=testing` does NOT target the testing database. The flag only swaps which `.env.<name>` file is loaded. With no `.env.testing` present, the command falls back to `.env` (production). To target a specific DB, prefix the command with `DB_DATABASE=paymenter_test APP_ENV=testing`. See incidents.md for the full forensic.

## phpunit.xml <env> values do not propagate to artisan

The `<env name="DB_DATABASE" value="paymenter_test"/>` block in phpunit.xml only applies inside the phpunit process. Any artisan command run separately (even `php artisan migrate ... --path=...`) reads `.env` directly.

## Test caches share the production cache backend

Without `<env name="CACHE_DRIVER" value="array"/>` in phpunit.xml, the Cache facade in tests writes to the same driver as production. Test runs can poison live cache keys. The dp-11 incident hit `cache key 'settings'` exactly as core commit `df7cdefc` warned about.

## APP_URL must be set explicitly in .env

Bootstrap config cache (`bootstrap/cache/config.php`) can mask a missing APP_URL by holding a previously-cached value. Any `php artisan config:clear` or `optimize:clear` removes that mask. Deploy process must template `APP_URL=https://<production-domain>` into `.env`.

## Mockery alias mock contamination (already noted)

`\Mockery::mock('alias:App\\Models\\Service')` in `CartItemDeletedListenerTest` permanently replaces the class for the rest of the PHP process. Subsequent tests calling `Service::factory()` get null. Workaround: use `DB::table('services')->insertGetId([...])` directly.

## Selective hunk staging (already noted)

`printf "y\ny\ny\ny\ny\nn\n" | git add -p <file>` to split unrelated hunks into separate commits.
