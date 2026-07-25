<?php

namespace App\Services\Service;

use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Exactly-once return of the finite product unit consumed at checkout.
 */
class ProductStockService
{
    public function release(Service|int $service): bool
    {
        $serviceId = $service instanceof Service ? (int) $service->id : $service;

        return DB::transaction(function () use ($serviceId): bool {
            $lockedService = Service::query()
                ->whereKey($serviceId)
                ->lockForUpdate()
                ->firstOrFail();
            $claimed = DB::table('services')
                ->where('id', $serviceId)
                ->whereNull('product_stock_released_at')
                ->update([
                    'product_stock_released_at' => now(),
                    'updated_at' => now(),
                ]);
            if ($claimed !== 1) {
                return false;
            }

            $product = $lockedService->product()
                ->lockForUpdate()
                ->first();
            if ($product?->stock !== null) {
                $product->increment('stock', (int) $lockedService->quantity);
            }

            return true;
        }, 5);
    }
}
