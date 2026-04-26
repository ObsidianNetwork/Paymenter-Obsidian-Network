# app/Admin — Filament 4 Admin Panel

Filament-discovered admin panel root. Panel wired in `app/Providers/Filament/AdminPanelProvider.php` (path `/admin`, id `admin`, SPA mode, `command+k`/`ctrl+k` global search).

## Structure

```
app/Admin/
├── Resources/      # ~30 CRUD resources: {Name}Resource.php + {Name}Resource/{Pages,Schemas,...}
├── Pages/          # Custom admin pages (non-CRUD)
├── Clusters/       # Grouped sections (Organization cluster, etc.)
├── Widgets/        # Dashboard widgets (CronStat/, etc.)
├── Components/     # Filament form/table components (reusable inputs)
└── Actions/        # Filament Actions shared across resources
```

## Where to look

| Task | Location |
|---|---|
| Add CRUD for a model | `Resources/<Name>Resource.php` + `Resources/<Name>Resource/Pages/{List,Create,Edit}<Name>.php` |
| Shared form schema | `Resources/<Name>Resource/Schemas/` (when split out) |
| Shared table filters/columns | `Resources/Common/` |
| Global admin action (bulk, row) | `Actions/` |
| Cross-resource dashboard metric | `Widgets/` |
| Top-nav group pages | `Clusters/` |

## Conventions

- Namespace: `App\Admin\...` (not `App\Filament\...`). Classes discovered by `discoverResources/Pages/Clusters` calls in `AdminPanelProvider::panel()`.
- Resource file pair: `FooResource.php` sits next to `FooResource/` subdir containing its pages/schemas. Keep that pairing.
- Blade partials for custom infolist/widget views live in `resources/views/admin/`, not here.
- Panel auth: middleware stack includes `ImpersonateMiddleware` — respect the impersonation context when adding guards.
- **Extensions can contribute Admin screens**: `AdminPanelProvider` also scans `extensions/*/Admin/{Resources,Pages,Clusters}` at boot — follow the same layout in any extension.

## Anti-patterns

- Do not create `app/Filament/` — it is not discovered. Everything under `app/Admin/`.
- Do not register resources via `->resources([...])` in the provider; rely on `discover*` auto-discovery.
- Do not import Filament v3 APIs (Forms/Tables namespaces changed in v4). Check `filament/filament ^4.0.0` docs.
- Do not hardcode colors; use `Filament\Support\Colors\Color` (panel primary is `Color::Blue`).
- Do not embed admin-only Livewire components here — those belong in `app/Livewire/Components/` or under the resource directory if Filament-owned.
