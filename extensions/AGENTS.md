# extensions/ — Plugin System

Runtime-loaded plugin tree. Composer maps `Paymenter\Extensions\` → `extensions/` (see `composer.json` autoload). Each extension is a PascalCase directory containing a single entry-point class of the same name that extends one of the base classes in `app/Classes/Extension/`.

## Layout

```
extensions/
├── Gateways/<Name>/<Name>.php         # extends App\Classes\Extension\Gateway
├── Servers/<Name>/<Name>.php          # extends App\Classes\Extension\Server
└── Others/<Name>/<Name>.php           # extends App\Classes\Extension\Extension
```

Per-extension optional subdirs (not standard Laravel — discovered by `AdminPanelProvider` / boot loops):

```
<Name>/
├── Admin/{Resources,Pages,Clusters}   # Filament contributions (merged into admin panel)
├── Http/                              # Controllers, Requests, Middleware
├── Livewire/                          # frontend components (public site)
├── Models/                            # Eloquent models
├── Listeners/, Policies/, Services/   # Laravel standard
├── database/migrations/               # run via `php artisan migrate` (auto-discovered)
├── database/factories/
├── routes/api.php, routes/web.php     # or a flat `routes.php` at extension root
├── resources/views/                   # Blade views (namespace-resolved)
└── install.txt                        # optional one-shot install notes
```

## Registration and boot

- Each extension class carries `#[App\Attributes\ExtensionMeta(name, description, version, author, url, icon)]`.
- Enable = insert/update row in the `extensions` DB table with `enabled=1`, `type ∈ {gateway, server, other}`, `extension = <Name>`. Autoload alone does **not** boot.
- `AppServiceProvider` iterates enabled rows and calls `ExtensionHelper::call($ext, 'boot')`. Put one-shot wiring (routes, event hooks, view registrations) in `boot()`.
- `bootstrap/app.php` also scans `app/Extensions` for event discovery — that path coexists with `extensions/` and is the canonical events source.

## Where to look

| Task | Location |
|---|---|
| Add payment method | `Gateways/<Name>/<Name>.php` → override `pay()`, `processPayment()`, webhooks in `routes.php` |
| Add provisioner | `Servers/<Name>/<Name>.php` → override `createServer()`, `suspendServer()`, `terminateServer()`, `testConfig()` |
| Non-gateway/server feature | `Others/<Name>/<Name>.php` (Blog, Affiliates, Announcements, SocialBase, etc.) |
| Admin UI for an extension | `<Name>/Admin/{Resources,Pages,Clusters}` — auto-merged into Filament panel |
| Dev-local work on a fork | `Others/DynamicPterodactyl/` is a nested git repo with its own `CLAUDE.md` |

## Conventions

- Entry class name = directory name = PHP file name (case-sensitive). `ExtensionHelper::getPath()` rebuilds this path; deviations break discovery.
- Use `$this->config('key')` from the base class — reads encrypted/non-encrypted values from settings. Do not hand-roll `Setting::where(...)`.
- Config fields declared in `getConfig()` method; encrypted values flagged `'encrypted' => true`.
- Gateway webhook routes go in `<Name>/routes.php` (flat file, required via `boot()`), not `routes/api.php` of the main app.
- Migrations in `<Name>/database/migrations/` run with the normal `php artisan migrate` once composer has autoloaded the extension's PSR-4.

## Anti-patterns

- Do not assume composer autoload is sufficient — the `extensions` DB row gates boot.
- Do not create `extensions/<Type>/<Name>/composer.json` (single composer at repo root manages everything).
- Do not place admin screens in `app/Admin/` for an extension — put them in the extension's own `Admin/` so they travel with the plugin.
- Do not use snake_case directory names; Filament discovery and `ExtensionHelper::getPath()` expect PascalCase.
- Do not commit from the outer repo when the extension subdir is its own git repo (e.g. `Others/DynamicPterodactyl/.git`). `cd` in first.
- No `DEPRECATED`/`FIXME`/`HACK` markers in this tree — TODOs only (most of them in `Others/DynamicPterodactyl/skeleton/`).

## Enforceable rules (CodeRabbit reads these)

- FAIL when: a `composer.json` is created under `extensions/<Type>/<Name>/`. Rationale: single `composer.json` at repo root manages the whole tree; per-extension composer files break autoload.
- FAIL when: a commit to `extensions/Others/DynamicPterodactyl/` is authored from the outer repo's working tree. Rationale: that subdirectory has its own `.git/`; commit inside the extension directory.
- FAIL when: an extension's admin screens are placed in `app/Admin/` instead of the extension's own `Admin/` subdirectory. Rationale: extension admin screens must travel with the plugin.
- FAIL when: directory names under `extensions/` use snake_case or kebab-case. Rationale: `ExtensionHelper::getPath()` and Filament discovery expect PascalCase.
