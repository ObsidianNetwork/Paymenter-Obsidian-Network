# app/Livewire — Public-Facing UI Components

All customer-facing pages (non-admin) are **full-page Livewire components** mounted by `routes/web.php`. Blade templates live under the active theme (`themes/<theme>/views/livewire/...`), not in `resources/views/`.

## Structure

```
app/Livewire/
├── Auth/{Login,Register,Tfa,VerifyEmail,Password/{Request,Reset}}.php
├── Client/{Account,Security,Credits,PaymentMethods,Notifications}.php
├── Invoices/{Index,Show}.php
├── Services/{Index,Show,Upgrade}.php
├── Tickets/{Index,Show,Create}.php
├── Products/{Index,Show,Checkout}.php        # public catalog + checkout
├── Components/                                # reusable subcomponents
├── Traits/                                    # shared Livewire traits
├── Home.php, Dashboard.php, Cart.php
├── Component.php                              # project base class — extend this, not Livewire\Component
└── ComponentWithProperties.php                # extensible "properties" JSON field support
```

## Where to look

| Task | Location |
|---|---|
| Add public page | new Livewire class here → register route in `routes/web.php` |
| Auth flow change | `Auth/` (mind `MustVerfiyEmail` middleware on protected routes) |
| Cart/checkout behavior | `Cart.php`, `Products/Checkout.php`, plus `App\Classes\Cart` |
| Authenticated account area | `Client/*` under `middleware(['web','auth'])` |

## Conventions

- Extend **`App\Livewire\Component`** — it wires permission helpers and project conventions. Plain `Livewire\Component` bypasses those.
- Route binding uses policies: `->middleware('can:view,invoice')` etc. Policies live in `app/Policies/`.
- Full-page mounts only (no inline `<livewire:...>` for the public site); components are bound directly as route targets (e.g. `Route::get('/dashboard', Dashboard::class)`).
- Component views resolve via `qirolab/laravel-themer` — put Blade at `themes/<theme>/views/livewire/<kebab-name>.blade.php`.
- Route alias conventions: `.name('dashboard')`, `.name('invoices.show')`, `.name('services.upgrade')` — match these when adding routes.

## Anti-patterns

- Do not place admin UI here — that belongs in `app/Admin/` (Filament).
- Do not render via `resources/views/livewire/...` — the themer resolves from `themes/<active>/views/`.
- Do not couple to a specific theme in PHP; templates live in themes, the component is theme-agnostic.
- Do not skip the `checkout` middleware on cart/product routes — it preserves checkout state across the session.
