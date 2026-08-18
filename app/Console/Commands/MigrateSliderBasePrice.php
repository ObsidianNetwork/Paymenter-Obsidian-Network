<?php

namespace App\Console\Commands;

use App\Models\ConfigOption;
use App\Models\Plan;
use App\Models\Product;
use App\Support\StrictDecimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateSliderBasePrice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'paymenter:migrate-slider-base-price
                            {--force : Apply changes (default is dry-run)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Collapse duplicate per-slider base_price values into a single plan-level dynamic_slider_base_price. '
        . 'Dry-run by default; use --force to mutate. '
        . 'Emits CSV: product_id,plan_id,before_total,after_total.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = !$this->option('force');

        if ($isDryRun) {
            $this->warn('DRY RUN — pass --force to apply changes.');
        }

        $this->line('product_id,plan_id,before_total,after_total');

        $products = Product::with([
            'configOptions',
            'plans.prices',
        ])->get();

        $operations = [];
        $conflicts = [];
        $changed = 0;

        foreach ($products as $product) {
            // Include retired/hidden sliders because historical services still
            // reference and price them during renewal.
            $sliders = ConfigOption::query()
                ->where('type', 'dynamic_slider')
                ->whereHas(
                    'products',
                    fn ($query) => $query->whereKey($product->id)
                )
                ->orderBy('id')
                ->get();

            if ($sliders->isEmpty()) {
                continue;
            }

            $legacyBases = [];
            foreach ($sliders as $slider) {
                $raw = data_get(
                    $slider->metadata,
                    'pricing.base_price',
                    0
                );
                $parsed = StrictDecimal::parseNonNegative(
                    $raw,
                    99_999_999.99
                );
                if ($parsed === null) {
                    $conflicts[] = "Product {$product->id} slider {$slider->id} has an invalid legacy base price.";

                    continue 2;
                }
                if ($parsed > 0) {
                    $legacyBases[(int) $slider->id] = $parsed;
                }
            }

            if ($legacyBases === []) {
                continue;
            }

            $distinctBases = collect($legacyBases)
                ->map(fn (float $base): string => number_format(
                    $base,
                    8,
                    '.',
                    ''
                ))
                ->unique()
                ->values();
            if ($distinctBases->count() !== 1) {
                $conflicts[] = "Product {$product->id} has conflicting per-slider base prices; resolve them manually.";

                continue;
            }
            if ($product->plans->isEmpty()) {
                $conflicts[] = "Product {$product->id} has a legacy base price but no plan to receive it.";

                continue;
            }

            $monthlyBase = (float) $distinctBases->first();
            $planOperations = [];
            foreach ($product->plans as $plan) {
                try {
                    $multiplier = $this->billingMultiplier($plan);
                } catch (\InvalidArgumentException $exception) {
                    $conflicts[] = "Product {$product->id} plan {$plan->id}: {$exception->getMessage()}";

                    continue 2;
                }
                $sharedBase = round($monthlyBase * $multiplier, 2);
                if (
                    !is_finite($sharedBase)
                    || $sharedBase < 0
                    || $sharedBase > 99_999_999.99
                ) {
                    $conflicts[] = "Product {$product->id} plan {$plan->id} base price exceeds the supported plan range.";

                    continue 2;
                }
                $currentBase = StrictDecimal::parseNonNegative(
                    $plan->dynamic_slider_base_price ?? 0,
                    99_999_999.99
                );
                if (
                    $currentBase === null
                    || (
                        $currentBase > 0
                        && number_format($currentBase, 2, '.', '')
                            !== number_format($sharedBase, 2, '.', '')
                    )
                ) {
                    $conflicts[] = "Product {$product->id} plan {$plan->id} already has a conflicting shared base price.";

                    continue 2;
                }

                $planPrice = (float) ($plan->prices->first()?->price ?? 0);
                $beforeTotal = $planPrice
                    + (array_sum($legacyBases) * $multiplier);
                $afterTotal = $planPrice + $sharedBase;

                $this->line("{$product->id},{$plan->id},{$beforeTotal},{$afterTotal}");
                $planOperations[] = [
                    'plan' => $plan,
                    'base' => $sharedBase,
                ];
                $changed++;
            }

            $operations[] = [
                'plans' => $planOperations,
                'sliders' => $sliders,
            ];
        }

        if ($conflicts !== []) {
            foreach ($conflicts as $conflict) {
                $this->error($conflict);
            }
            $this->error(
                'No pricing records were changed. Resolve every conflict and rerun the command.'
            );

            return Command::FAILURE;
        }

        if (!$isDryRun) {
            DB::transaction(function () use ($operations): void {
                foreach ($operations as $operation) {
                    foreach ($operation['plans'] as $planOperation) {
                        $plan = $planOperation['plan'];
                        $plan->dynamic_slider_base_price =
                            $planOperation['base'];
                        $plan->save();
                    }
                    foreach ($operation['sliders'] as $option) {
                        $metadata = $option->metadata ?? [];
                        $metadata['pricing']['base_price'] = 0;
                        $option->metadata = $metadata;
                        $option->save();
                    }
                }
            }, 5);
        }

        if ($isDryRun) {
            $this->warn("Dry run complete. {$changed} plan(s) would be updated. Re-run with --force to apply.");
        } else {
            $this->info("Migration complete. {$changed} plan(s) updated.");
        }

        return Command::SUCCESS;
    }

    private function billingMultiplier(Plan $plan): float
    {
        $period = (int) ($plan->billing_period ?? 1);
        if ($period < 1) {
            throw new \InvalidArgumentException(
                'billing period must be positive.'
            );
        }

        return match ($plan->billing_unit) {
            'day' => $period / 30,
            'week' => $period / 4,
            'month' => $period,
            'year' => $period * 12,
            null, '' => 1.0,
            default => throw new \InvalidArgumentException(
                'billing unit is not supported.'
            ),
        };
    }
}
