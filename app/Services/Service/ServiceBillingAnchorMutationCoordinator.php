<?php

namespace App\Services\Service;

use App\Models\Service;
use App\Models\ServiceUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * Serializes administrative billing-anchor edits with upgrade creation.
 */
class ServiceBillingAnchorMutationCoordinator
{
    /** @var array<int, int> */
    private static array $serviceIds = [];

    public static function isCoordinating(Service $service): bool
    {
        return isset(self::$serviceIds[(int) $service->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Service $service, array $attributes): Service
    {
        return DB::transaction(function () use (
            $service,
            $attributes
        ): Service {
            $locked = Service::query()
                ->whereKey($service->id)
                ->lockForUpdate()
                ->firstOrFail();
            $billingChanges = collect([
                'expires_at',
                'price',
                'period_base_price',
                'current_period_price',
                'pricing_ledger_started_at',
                'pricing_ledger_verified_at',
                'billing_cycles_completed',
                'coupon_id',
            ])->contains(function (string $attribute) use (
                $attributes,
                $locked
            ): bool {
                if (!array_key_exists($attribute, $attributes)) {
                    return false;
                }

                $candidate = $locked->newInstance()
                    ->forceFill([$attribute => $attributes[$attribute]]);

                return $candidate->getAttribute($attribute)
                    != $locked->getAttribute($attribute);
            });

            if ($billingChanges) {
                $activeUpgrade = ServiceUpgrade::query()
                    ->where('service_id', $locked->id)
                    ->whereIn('status', ServiceUpgrade::activeStatuses())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->exists();
                if ($activeUpgrade) {
                    throw new \RuntimeException(
                        'Service expiration, price, and coupon cannot change while an upgrade is active.'
                    );
                }
            }

            self::run(
                $locked,
                function () use ($locked, $attributes): void {
                    $locked->fill($attributes);
                    $locked->save();
                }
            );

            return $locked->fresh();
        }, 5);
    }

    public static function run(
        Service $service,
        callable $mutation
    ): mixed {
        $serviceId = (int) $service->getKey();
        self::$serviceIds[$serviceId] =
            (self::$serviceIds[$serviceId] ?? 0) + 1;

        try {
            return $mutation();
        } finally {
            self::$serviceIds[$serviceId]--;
            if (self::$serviceIds[$serviceId] === 0) {
                unset(self::$serviceIds[$serviceId]);
            }
        }
    }
}
