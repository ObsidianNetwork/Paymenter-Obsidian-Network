# Paymenter Project Context

Obsidian Network's Paymenter fork, currently based on **v1.5.7**. This repository is a development checkout; production deployment state and filesystem ownership are managed separately.

## Stack

- **Laravel 12** on **PHP 8.3+** (`^8.3 || ^8.4`)
- **Filament 5.0** admin panel (installed under `app/Admin/`, not `app/Filament/`)
- **Livewire 4** for the public-facing UI (auth, dashboard, cart, products, invoices, tickets) — see `app/Livewire/AGENTS.md`
- **Tailwind CSS 4.1** via **Vite 7** with a custom `vite.js` wrapper that builds per-theme
- `qirolab/laravel-themer` for swappable front-end themes — see `themes/AGENTS.md`
- Laravel Passport (OAuth2 admin API), Socialite (+Discord provider), `owen-it/laravel-auditing`
- Misc: `barryvdh/laravel-dompdf`, `endroid/qr-code`, `minishlink/web-push`, `directorytree/imapengine`, `dedoc/scramble` (API docs, dev only)

## Structure

```
/var/www/paymenter/
├── app/
│   ├── Admin/              # Filament 5 panel (Resources/Pages/Clusters/Widgets/Actions) — see app/Admin/AGENTS.md
│   ├── Attributes/         # #[ExtensionMeta] class attribute for extensions
│   ├── Classes/
│   │   ├── Extension/      # Base classes: Extension, Gateway, Server (extensions subclass these)
│   │   ├── helpers.php     # theme(), hook() global helpers (include_once'd by SettingsProvider::boot) — see app/Classes/AGENTS.md
│   │   └── Settings.php, Theme.php, Cart.php, Price.php, PDF.php, FilamentInput.php, Navigation.php
│   ├── Helpers/            # ExtensionHelper (discovery/boot), EventHelper, NotificationHelper
│   ├── Livewire/           # Public UI components — see app/Livewire/AGENTS.md
│   ├── Models/             # Eloquent models; base Model.php, several use Auditable trait
│   ├── Providers/
│   │   ├── AppServiceProvider.php         # Extension boot loop, macros, Scramble config
│   │   ├── SettingsProvider.php           # Loads DB settings into config('settings.*') at boot
│   │   └── Filament/AdminPanelProvider.php# Panel config + discovers extensions' Admin/
│   └── ...                 # Standard: Http/, Events/, Listeners/, Jobs/, Observers/, Policies/, Mail/
├── bootstrap/app.php       # Laravel 12 central config; event discovery includes app/Extensions + app/Listeners
├── extensions/             # Plugin system — see extensions/AGENTS.md
├── themes/                 # default, obsidian — see themes/AGENTS.md
├── resources/css/filament/admin/   # Admin panel Tailwind theme (separate config + build)
├── resources/views/admin/  # Filament infolist/widget/page partials
├── routes/                 # web.php (Livewire routes), api.php (Passport), console.php (schedule)
├── tests/                  # PHPUnit 12 (Unit + Feature); SQLite and MariaDB 11/12 CI
└── vite.js                 # Node shim: `node vite.js [theme]` builds, `node vite.js dev [theme]`
```

## Where to look

| Task | Location |
|---|---|
| Add admin CRUD screen | `app/Admin/Resources/` (see `app/Admin/AGENTS.md`) |
| Add public page / form | `app/Livewire/` + route in `routes/web.php` |
| New payment gateway | `extensions/Gateways/<Name>/<Name>.php` extending `App\Classes\Extension\Gateway` |
| New server provisioner | `extensions/Servers/<Name>/<Name>.php` extending `App\Classes\Extension\Server` |
| Tweak theme colors / layout | `themes/<name>/` (never `resources/views/`) |
| Background jobs / cron | `routes/console.php`, `app/Jobs/`, `app/Console/Commands/` |
| Middleware aliases | `bootstrap/app.php` (`has`, `scope`, `api.admin`, `checkout`) |

## Conventions

- Laravel 12 layout: no `app/Console/Kernel.php`, no `app/Http/Kernel.php` — everything in `bootstrap/app.php`. `channels.php` is intentionally **not** registered.
- No `strict_types` declarations — match surrounding files.
- **Pint** (`pint.json`): Laravel preset + `concat_space: one` + `not_operator_with_successor_space: false`. CI runs `pint --test` and never rewrites the branch.
- **PHPStan**: larastan level 5, `app/` only (`phpstan.neon`). Not run in CI (commented out in `lint.yaml`).
- **Tests**: PHPUnit 12 (not Pest). Feature tests use `RefreshDatabase`. Base `Tests\TestCase` has `$seed = true`. CI covers SQLite plus digest-pinned MariaDB 11 and 12 on PHP 8.3 and 8.4.
- **Extensions** use the `Paymenter\Extensions\` PSR-4 root → `extensions/`. Extension classes carry `#[App\Attributes\ExtensionMeta(name, description, version, author, url, icon)]`.
- Extensions are enabled/disabled at **runtime via the `extensions` DB table** — composer autoload alone doesn't boot them.

## Anti-patterns (this project)

- Do **not** drop files in `app/Filament/` — Filament is wired to `app/Admin/` (`discoverResources(in: app_path('Admin/Resources'), for: 'App\\Admin\\Resources')`).
- Do **not** put theme Blade overrides under `resources/views/`; use `themes/<theme>/views/`. `resources/views/` is reserved for admin-panel partials, mail components, and the invoice PDF.
- Do **not** invoke `npx vite` directly — it skips per-theme config resolution. Use `npm run dev` / `npm run build` / `node vite.js <theme>`.
- Do **not** reimplement dynamic discovery — `App\Helpers\ExtensionHelper` plus `AppServiceProvider` already walk the `extensions` table and instantiate classes.
- Do **not** edit generated assets in `public/build/`, `public/css/filament/admin/theme.css`, or `storage/framework/views/` — regenerate via build commands.

## Commands

```bash
# PHP
vendor/bin/pint                     # format (use --dirty for changed files only)
php artisan migrate                 # run migrations
php artisan tinker
php artisan optimize:clear          # clear all caches (config, view, route, events)
php artisan queue:restart           # after deploys
php artisan schedule:work           # local scheduler (prod uses cron every minute)
php artisan test                    # or: vendor/bin/phpunit

# Frontend (public / theme)
npm run dev                         # vite dev server for default theme
npm run build                       # vite production build (default theme)
node vite.js obsidian               # build the obsidian theme specifically
node vite.js dev obsidian           # dev server for obsidian theme

# Filament admin panel CSS (separate tailwind config at resources/css/filament/admin/)
npm run dev:admin
npm run build:admin
```

## Gotchas

- **File ownership**: editing as `root` yields root-owned files the web worker can't read/write. Run `chown -R www-data:www-data <path>` after edits (especially anything under `storage/` or `bootstrap/cache/`).
- **This is a fork** whose default branch retains the historical name `dynamic-slider/1.4.7`; the current release baseline is v1.5.7. `extensions/Others/DynamicPterodactyl/` has its own git repo and its own `CLAUDE.md` — don't commit changes there from the outer repo.
- **Filament 5 ≠ 4**: APIs changed significantly. Check the installed v5 contracts before porting older snippets.
- **Settings cache**: `config('settings.*')` comes from the DB via `SettingsProvider` (runs before panel boot). If a setting read returns stale data, clear cache and re-query.
- **Extension autoload vs enable**: adding files under `extensions/` is not enough — the row in the `extensions` table must have `enabled=1` and the correct `type`/`extension` before `boot()` fires.
- **Release version lives in `config/app.php`**. The release workflow replaces its `development` value with the tag version on the release branch.
- **API docs** use the paid `dedoc/scramble` plugin in CI; local `composer install --no-dev` will skip it.

## Enforceable rules (CodeRabbit reads these)

- FAIL when: files are added under `app/Filament/`. Rationale: Filament is wired to `app/Admin/` — `app/Filament/` is not scanned.
- FAIL when: Blade templates are placed under `resources/views/<theme>/` (any theme subdirectory). Rationale: theme-specific views belong in `themes/<theme>/views/`; `resources/views/` is reserved for admin partials, mail, and invoice PDF.
- FAIL when: `npx vite` is invoked in scripts or CI config. Rationale: skips per-theme config resolution — use `node vite.js <theme>` or `npm run build` instead.
- FAIL when: files under `public/build/`, `public/css/filament/`, or `storage/framework/views/` are committed. Rationale: these are generated — committing them causes merge conflicts and stale cache issues.
- FAIL when: a commit touches files under `extensions/Others/DynamicPterodactyl/` from the outer Paymenter working tree. Rationale: that path is a nested git repo with its own `.git/`; commit from inside the extension directory.
