# app/Classes — Extension Bases, Domain Helpers, Livewire Synths

Non-standard bucket for (a) the abstract base classes that **every** extension in `extensions/` subclasses and (b) domain helpers used across Livewire/Filament/PDF code. Nothing in here is auto-discovered by Laravel — registration happens in `SettingsProvider::boot()` and `AppServiceProvider`.

## Structure

```
app/Classes/
├── Extension/
│   ├── Extension.php     # base for "other" extensions — config() uses debug_backtrace() to pick model
│   ├── Gateway.php       # abstract pay(); optional supportsBillingAgreements/createBillingAgreement/charge/cancel
│   └── Server.php        # currently a stub `extends Extension` — provisioners override at will
├── Synths/
│   └── PriceSynth.php    # Livewire synth for App\Classes\Price (hydrate/dehydrate across requests)
├── helpers.php           # theme($key, $default), hook($event) — loaded via include_once in SettingsProvider::boot
├── Cart.php              # session cart + checkout state (225 LOC)
├── FilamentInput.php     # reusable form fields for Filament 4 admin (307 LOC)
├── Navigation.php        # public-site nav tree (260 LOC)
├── PDF.php + Pdf/        # dompdf wrappers (Content/File)
├── Price.php             # money value object; pairs with Synths/PriceSynth
├── Settings.php          # static settings() tree consumed by Filament + SettingsProvider (710 LOC)
└── Theme.php             # `qirolab/laravel-themer` wiring — Theme::set + getSettings prefixing
```

## Conventions

- Extensions call `$this->config('key')` — the base `Extension::config()` walks `debug_backtrace()` to pick `Server`/`Gateway`/`Extension` model scope. Do not rewrite that lookup.
- `Gateway::pay(Invoice, $total)` is the only abstract; billing-agreement methods throw "Not implemented" by default — override only if the provider supports them.
- `Server.php` is intentionally a thin `extends Extension` — server provisioners define their own contract (`createServer`, `suspendServer`, `terminateServer`, `testConfig`) per Paymenter docs; no abstract enforcement.
- Blade/PHP: always use global `theme('key')` and `hook('event')` — both defined in `helpers.php`. Filament uses `theme()` inside layout partials.
- `Price` instances must round-trip through `PriceSynth` when stored in Livewire component state — register it via `Livewire::propertySynthesizer(...)` (see `AppServiceProvider`).

## Anti-patterns

- Do not add this file to composer `autoload.files` — `helpers.php` is loaded by `SettingsProvider::boot` via `include_once app_path('Classes/helpers.php')` (so helpers are available only after settings boot).
- Do not instantiate an extension class manually with `new Gateway(...)` — go through `App\Helpers\ExtensionHelper::call($ext, $method)` so the `extensions` DB row is resolved and `config` is hydrated.
- Do not add new abstract methods to `Extension.php` without updating every subclass in `extensions/` — the codebase relies on graceful defaults (empty `getConfig()`, empty `boot()`, etc.).
- Do not put plain static utility classes here — use `app/Helpers/` for stateless helpers and `app/Services/` for orchestration.
- Do not bypass `Settings::settings()` to hardcode config schema in Filament — the tree is shared by admin UI and `SettingsProvider`.
