# app/Models — Eloquent Models + Shared Traits/Builders

~50 Eloquent models backing the billing domain (users, products, services, invoices, orders, tickets, extensions, settings). Model layer, not service layer — keep query scopes and relationships here; put workflows in `app/Services/`.

## Structure

```
app/Models/
├── Model.php              # project base: `class Model extends \Illuminate\Database\Eloquent\Model {}` (currently empty shell — extend for future cross-cutting behavior)
├── Builders/
│   └── CacheableBuilder.php   # 1h Cache::remember wrapper around get()/first(); used by Currency only
├── Traits/
│   ├── Auditable.php      # wraps owen-it/laravel-auditing; tags rows 'admin' when URL matches /admin
│   ├── HasPlans.php       # attaches Plan relations (price per billing cycle)
│   ├── HasProperties.php  # polymorphic custom Property bag (see CustomProperty model)
│   └── Settingable.php    # morphable `settings` JSON on gateways/servers/extensions
└── {Cart, Category, Coupon, Credit, Currency, Extension, Gateway, Invoice, Order, Product,
    Role, Server, Service, Ticket, User, ...}.php    # ~50 domain models
```

## Conventions

- Most billing-adjacent models (Invoice, InvoiceItem, Order, Product, Category, Coupon, Credit, Extension, Plan, Property, ProductUpgrade, ConfigOption, ConfigOptionProduct, NotificationTemplate, CustomProperty, BillingAgreement, Price, ApiKey) `use App\Models\Traits\Auditable` — inherits `OwenIt\Auditing\Auditable` plus Paymenter's admin-context tagging. Add the trait on any new model that should appear in the Audit log.
- `Model.php` is an empty shell — extending it (instead of Eloquent directly) is the project convention, but add shared behavior to traits, not this base.
- To cache queries on a model, override `newEloquentBuilder()` returning `new \App\Models\Builders\CacheableBuilder($query)` — mirror `Currency.php` (only current consumer).
- `Gateway`, `Server`, and `Extension` models are **settings holders**, not extension entry points. The PHP classes live in `extensions/` / `app/Classes/Extension/`; these models persist rows in the `extensions`/`gateways`/`servers` tables.
- Factories live at `database/factories/` (PSR-4 `Database\Factories\`) — ~12 factories, mostly feature-test fixtures (User, Product, Invoice, etc.).

## Anti-patterns

- Do not `use OwenIt\Auditing\Auditable` directly — always route through `App\Models\Traits\Auditable` so the admin URL tagging applies.
- Do not add business workflows (invoice payment, service provisioning, cart mutation) as model methods — those belong in `app/Services/` or `app/Classes/` (e.g. `Cart.php`).
- Do not cache via `remember()` calls inside model methods — use `CacheableBuilder` so cache invalidation stays in one place (caveat: current invalidation is TTL-only; stale data possible for 1h).
- Do not use the `Extension` / `Gateway` / `Server` models to drive extension behavior — load the extension **class** via `App\Helpers\ExtensionHelper`; these models are for persisted settings only.
- Do not leak encrypted setting values — `Setting` rows with `encrypted=true` must stay behind the cast; read via `$extension->settings->pluck('value','key')` and let Eloquent decrypt.
