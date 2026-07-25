# DynamicPterodactyl — Pricing Config Validation

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/`
**Type**: Add validation at both write (SetupWizard) and read (PricingCalculatorService) boundaries so malformed pricing JSON can't ship to customers.

---

## Problem

Audit finding #6. `PricingCalculatorService` consumes JSON pricing config from `ConfigOption.metadata`. No validation at:

- **Write time**: `SetupWizard` / `ConfigOptionSetupService` accepts whatever admin types into the form.
- **Read time**: `PricingCalculatorService::calculate()` assumes required keys exist. Missing/malformed keys → PHP warnings, null returns, or worse — silent zero-price.

Failure mode: admin fat-fingers pricing JSON → customers pay $0 or $NaN or get a 500 on checkout. Found only by the customer.

The three pricing models to validate (per `07-PRICING-MODELS.md`):
- **linear**: `{ per_gb_memory: 0.5, per_core_cpu: 2.0, per_gb_disk: 0.1 }`
- **tiered**: `{ memory_tiers: [{ up_to_gb: 4, per_gb: 1.00 }, { up_to_gb: 16, per_gb: 0.80 }, ...] }`
- **base_plus_addon**: `{ included: { memory_gb: 4, cpu_cores: 1, disk_gb: 20 }, addon: { per_gb_memory: 0.5, ... } }`

---

## Design

### Two-layer validation

**Layer 1 — write-time (hard fail)**
SetupWizard submit → reject bad JSON with a Filament validation error. Admin sees the error, fixes it, re-submits.

**Layer 2 — read-time (fail-safe)**
`PricingCalculatorService::calculate()` wraps its logic in a try/catch. On validation failure: log warning, return `['total' => 0, 'breakdown' => [], 'error' => '...']` and let the caller decide (checkout will almost certainly refuse to proceed — that's fine).

Why both:
- Write-time catches everything new. Read-time catches legacy rows and any validation that slips past (e.g., direct DB updates, imports).

### Validator class

Create `Services/Validation/PricingConfigValidator.php`:

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services\Validation;

class PricingConfigValidator
{
    /** @throws InvalidPricingConfigException */
    public function validate(array $config): void
    {
        $model = $config['model'] ?? null;
        match ($model) {
            'linear' => $this->validateLinear($config),
            'tiered' => $this->validateTiered($config),
            'base_plus_addon' => $this->validateBasePlusAddon($config),
            default => throw new InvalidPricingConfigException(
                "Unknown pricing model: " . var_export($model, true)
            ),
        };
    }

    private function validateLinear(array $config): void
    {
        foreach (['per_gb_memory', 'per_core_cpu', 'per_gb_disk'] as $key) {
            if (! isset($config[$key])) {
                throw new InvalidPricingConfigException("Missing required key: {$key}");
            }
            if (! is_numeric($config[$key]) || $config[$key] < 0) {
                throw new InvalidPricingConfigException("{$key} must be a non-negative number");
            }
        }
    }

    private function validateTiered(array $config): void
    {
        foreach (['memory_tiers', 'cpu_tiers', 'disk_tiers'] as $key) {
            if (! isset($config[$key])) {
                throw new InvalidPricingConfigException("Missing required key: {$key}");
            }
            if (! is_array($config[$key]) || empty($config[$key])) {
                throw new InvalidPricingConfigException("{$key} must be a non-empty array of tiers");
            }
            $previousCap = 0;
            foreach ($config[$key] as $i => $tier) {
                if (! isset($tier['up_to_gb'], $tier['per_gb'])) {
                    throw new InvalidPricingConfigException("{$key}[{$i}] missing up_to_gb or per_gb");
                }
                if (! is_numeric($tier['up_to_gb']) || $tier['up_to_gb'] <= $previousCap) {
                    throw new InvalidPricingConfigException(
                        "{$key}[{$i}].up_to_gb must be strictly greater than previous tier ({$previousCap})"
                    );
                }
                if (! is_numeric($tier['per_gb']) || $tier['per_gb'] < 0) {
                    throw new InvalidPricingConfigException("{$key}[{$i}].per_gb must be non-negative");
                }
                $previousCap = $tier['up_to_gb'];
            }
        }
    }

    private function validateBasePlusAddon(array $config): void
    {
        if (! isset($config['included'], $config['addon'])) {
            throw new InvalidPricingConfigException("Missing 'included' or 'addon' block");
        }
        foreach (['memory_gb', 'cpu_cores', 'disk_gb'] as $key) {
            if (! isset($config['included'][$key]) || ! is_numeric($config['included'][$key])) {
                throw new InvalidPricingConfigException("included.{$key} must be numeric");
            }
        }
        foreach (['per_gb_memory', 'per_core_cpu', 'per_gb_disk'] as $key) {
            if (! isset($config['addon'][$key]) || ! is_numeric($config['addon'][$key]) || $config['addon'][$key] < 0) {
                throw new InvalidPricingConfigException("addon.{$key} must be non-negative number");
            }
        }
    }
}

class InvalidPricingConfigException extends \RuntimeException {}
```

Exact field names MUST match what `PricingCalculatorService` already reads. **Pre-step**: read that service and align this validator's key set to its consumer.

### Wire write-time
In `ConfigOptionSetupService` (or wherever SetupWizard submits):

```php
public function savePricingConfig(int $productId, array $pricingConfig): void
{
    app(PricingConfigValidator::class)->validate($pricingConfig);
    // existing persistence code
}
```

On exception, SetupWizard catches and surfaces via Filament:
```php
try {
    $this->setupService->savePricingConfig(...);
} catch (InvalidPricingConfigException $e) {
    Notification::make()
        ->title('Pricing config rejected')
        ->body($e->getMessage())
        ->danger()
        ->send();
    $this->halt();
}
```

Confirm Filament 4 notification API matches — grep one working Filament notification call in Paymenter.

### Wire read-time
In `PricingCalculatorService::calculate`:

```php
public function calculate(int $productId, array $resources): array
{
    $config = $this->loadConfigForProduct($productId); // existing

    try {
        app(PricingConfigValidator::class)->validate($config);
    } catch (InvalidPricingConfigException $e) {
        Log::warning('Pricing config invalid', [
            'product_id' => $productId,
            'error' => $e->getMessage(),
            'config' => $config,
        ]);
        return [
            'total' => 0.0,
            'breakdown' => [],
            'error' => 'invalid_pricing_config',
        ];
    }

    // existing calc logic
}
```

Callers (`CartItemCreatedListener`, API) already handle low/zero prices. Returning `error` key is additive; they can ignore it or route to an alert.

---

## Testing

### Unit — `tests/Unit/PricingConfigValidatorTest.php`

Create new. Data-provider driven:

1. Valid linear config → no exception
2. Valid tiered config → no exception
3. Valid base_plus_addon → no exception
4. Unknown model → exception with message
5. Missing key per model → exception per missing key
6. Negative prices → exception
7. Non-numeric prices → exception
8. Tiered: overlapping/decreasing caps → exception
9. Tiered: empty array → exception
10. Base+addon: non-numeric included resources → exception

Aim for ~20 cases via `@dataProvider`. This validator is the kind of code that will be read far more than it's written; invest in tests.

### Unit — extend `PricingCalculatorServiceTest`
1. `test_calculate_returns_zero_and_error_on_invalid_config`

### Feature — `tests/Feature/SetupWizardValidationTest.php` (if feasible)
1. Submitting wizard with invalid pricing JSON → Filament error surfaces, no DB write.

### Manual
1. Edit a ConfigOption's metadata via tinker to an invalid pricing config.
2. Simulate checkout → log shows warning, price=0 returned.
3. Try SetupWizard with `{"model":"linear","per_gb_memory":-5}` → Filament rejects with "non-negative" message.

---

## Risks

| Risk | Mitigation |
|---|---|
| Field names in validator differ from actual `PricingCalculatorService` keys | Pre-step: read the service and align. Deliberately add a test that round-trips a known-good config through both. |
| Legacy data in production fails new validation | Read-time returns `{total: 0, error: ...}` — safe fail. Admin sees customer complaint, fixes config via wizard. Surface in dashboard if wanted. |
| Filament validation API differs in v4 | Grep existing Filament v4 notification/validation calls in Paymenter before coding. |
| Admin wizard is complex — per-tier fields, dynamic arrays | Keep validator logic outside of Filament concerns; validator only cares about array shape. Wizard is a separate concern. |
| Validator exception leaks to customer in API path | `PricingCalculatorService::calculate` catches and returns structured error. API returns `500` only on truly unexpected errors. |

---

## Acceptance

- `PricingConfigValidator` + `InvalidPricingConfigException` exist and are PSR-4 autoloadable.
- All three pricing models validated (linear, tiered, base_plus_addon).
- SetupWizard rejects bad configs with a Filament error banner.
- `PricingCalculatorService::calculate` never returns negative / NaN / missing-key prices — returns structured `{total: 0, error: '...'}` on invalid.
- Tests pass; coverage of validator ≥ 20 cases.
- Existing good configs continue to work unchanged.

---

## Commit

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git add -A
git commit -m "feat(pricing): validate pricing config at write and read boundaries"
```

---

## Delegation

`task(category="deep", load_skills=[], run_in_background=true, ...)`

Branch setup (run before delegating):

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git fetch origin
git checkout -b dp-06-pricing-config-validation origin/dynamic-slider
```

Agent MUST:
1. Read `Services/PricingCalculatorService.php` FIRST to confirm exact field names used.
2. Read `Admin/Pages/SetupWizard.php` to find submit handler.
3. Read one existing Filament 4 notification call in Paymenter (`grep -rn "Notification::make" /var/www/paymenter/app/Admin/`).
4. Implement validator, exception, two wire points.
5. Write tests (validator + calculator + wizard feature).
6. Run `phpunit` from inside extension dir.
7. Commit.

Publish for review:

```bash
git push -u origin dp-06-pricing-config-validation
gh pr create --base dynamic-slider --title "feat(pricing): validate pricing config at write and read boundaries" --fill
```

---

## Out of scope

- Price range sanity checks (e.g., reject $1000/GB as "probably a typo") — separate concern, needs UX thought.
- Multi-currency — Paymenter core handles currency; validator is currency-agnostic.
- Versioning of pricing configs for historical repricing — separate epic.
- Admin preview / "what would this cost for 8GB?" in SetupWizard — UX feature, separate plan.
