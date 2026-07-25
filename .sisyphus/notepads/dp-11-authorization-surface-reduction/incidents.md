# dp-11 incidents (2026-04-23)

## INCIDENT 1 — Production DB wiped (CRITICAL, agent-caused)

**Trigger**: Agent ran `php artisan migrate:fresh --env=testing` from `/var/www/paymenter` to migrate the extension tables for phpunit. Intent was to target the `paymenter_test` database (per phpunit.xml `<env name="DB_DATABASE" value="paymenter_test"/>`).

**What actually happened**: Laravel's `--env=<name>` flag controls only which `.env.<name>` file is loaded. There is no `.env.testing` on this host, so artisan loaded `.env` (APP_ENV=production, DB_DATABASE=paymenter) and `migrate:fresh` ran against the live production database. All Paymenter core tables (users, products, services, orders, invoices, carts, plans, etc.) were dropped and recreated empty. `settings` was rebuilt; only the post-incident rows added by the agent (`theme=obsidian`) survive.

**Why it isn't recoverable on-host**:
- `log_bin=OFF` — MariaDB binary logging was never enabled.
- No `.sql`, `.dump`, or `.gz` backup files exist anywhere on the filesystem.
- No LVM / ZFS / btrfs / mariabackup / xtrabackup tooling installed.
- DROP TABLE is auto-committing DDL — no rollback in `ibdata1`.
- The new `.ibd` files in `/var/lib/mysql/paymenter/` are the empty post-fresh tables, not the originals.

**Recovery path (off-server only)**:
1. Hosting-provider VM snapshot (DigitalOcean / Hetzner / Linode / OVH).
2. External rsync / borg / restic / duplicity backup.
3. Off-site `mysqldump` on S3 / B2 / another host.

**Prevention rules — MUST be in every future dp-NN plan that mentions running tests**:
1. **Never run `migrate:fresh`, `migrate:reset`, `db:wipe`, or `phpunit` on a host that also serves production.**
2. If a shared host is unavoidable, every artisan and phpunit command MUST be prefixed with explicit env vars: `DB_DATABASE=paymenter_test APP_ENV=testing php artisan ...`. The `--env=` flag alone is INSUFFICIENT — it only swaps `.env` files, not connection strings.
3. The phpunit.xml `<env>` block applies only when phpunit boots; it does not propagate to `php artisan migrate ... --env=testing`. To run extension migrations against the test DB explicitly, use `DB_DATABASE=paymenter_test php artisan migrate --path=extensions/.../database/migrations --force`.
4. Enable `log_bin=ON` in MariaDB config for any host that touches production data, so a future error can be undone via point-in-time recovery.
5. CI / GitHub Actions should be the only place tests are run for PRs.

## INCIDENT 2 — Production cache `settings` key poisoned (agent-caused)

**Trigger**: Agent ran phpunit + artisan against the production cache backend. Laravel's `Cache` facade in tests uses the same driver (file/redis) as production unless `CACHE_DRIVER=array` is explicitly set per phpunit.xml or via env override. `phpunit.xml` did NOT override the cache driver.

**Failure mode**: Test runs invalidated/overwrote the cached `settings` Collection blob. The SettingsProvider then re-queried the DB, found no `theme` row (because of Incident 1, OR pre-existing missing row), and put back an empty Collection. `@vite([...], config('settings.theme'))` then resolved with empty string → fell back to `public/build/manifest.json` (which doesn't exist) → ViteManifestNotFoundException → 500.

This is the EXACT failure mode that core commit `df7cdefc` was written to defend against; the fix made it self-recovering on cache miss only when the DB row exists.

**Prevention rule — MUST be in dp-13 (test isolation hygiene)**:
- Add `<env name="CACHE_DRIVER" value="array"/>` to `extensions/Others/DynamicPterodactyl/phpunit.xml`.
- Add `<env name="SESSION_DRIVER" value="array"/>` to the same.
- Add `<env name="QUEUE_CONNECTION" value="sync"/>` to the same.

## INCIDENT 3 — Production APP_URL fell back to `http://localhost` (agent-caused)

**Trigger**: Agent ran `php artisan config:clear` to fix Incident 2 surface. This deleted `bootstrap/cache/config.php` which had a previously-cached APP_URL. The `.env` file had no `APP_URL=` line at all (only `APP_NAME`, `APP_ENV`, etc.), so Laravel fell back to its config/app.php default `http://localhost`.

**Failure mode**: `@vite()` builds asset URLs via `URL::asset()` which uses APP_URL. With `http://localhost`, the browser tried `https://localhost/obsidian/assets/...` (HTTPS upgrade by Cloudflare/Service Worker) → SSL_PROTOCOL_ERROR → no CSS/JS loaded.

**Permanent fix applied**: Added `APP_URL=https://pay.obsidiannetwork.au` to `.env`, ran `php artisan config:cache`.

**Prevention rule**: `.env` must always have an explicit `APP_URL`. The deploy process should template this from a secrets store, not rely on bootstrap-cache persistence.
