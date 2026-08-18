<?php

use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('services')
                ->whereNull('current_period_price')
                ->orderBy('id')
                ->chunkById(500, function ($services): void {
                    $serviceIds = $services
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->all();
                    $couponIds = $services
                        ->pluck('coupon_id')
                        ->filter()
                        ->map(fn ($id): int => (int) $id)
                        ->unique()
                        ->all();
                    $recurringByCoupon = empty($couponIds)
                        ? collect()
                        : DB::table('coupons')
                            ->whereIn('id', $couponIds)
                            ->pluck('recurring', 'id');
                    $paidCyclesByService = DB::table('invoice_items')
                        ->join(
                            'invoices',
                            'invoices.id',
                            '=',
                            'invoice_items.invoice_id'
                        )
                        ->where(
                            'invoice_items.reference_type',
                            Service::class
                        )
                        ->whereIn(
                            'invoice_items.reference_id',
                            $serviceIds
                        )
                        ->where(
                            'invoices.status',
                            Invoice::STATUS_PAID
                        )
                        ->groupBy('invoice_items.reference_id')
                        ->selectRaw(
                            'invoice_items.reference_id AS service_id, COUNT(DISTINCT invoices.id) AS paid_cycles'
                        )
                        ->get()
                        ->pluck('paid_cycles', 'service_id');

                    foreach ($services as $service) {
                        $recurring = $service->coupon_id === null
                            ? null
                            : $recurringByCoupon->get(
                                $service->coupon_id
                            );
                        $paidCycles = (int) $paidCyclesByService
                            ->get($service->id, 0);
                        // Historical zero-price renewals left no invoice
                        // evidence, so finite coupon usage is not
                        // reconstructable. Conservatively exhaust the coupon
                        // rather than risk silently making it lifetime-free.
                        // Positive-price cycles retain their reconstructable
                        // paid-invoice count and therefore do not lose a
                        // promised finite discount prematurely.
                        $finiteCoupon =
                            $recurring !== null
                            && (int) $recurring > 0;
                        $completedCycles = $finiteCoupon
                            ? (
                                (float) ($service->price ?? 0) <= 0
                                    ? (int) $recurring
                                    : min(
                                        (int) $recurring,
                                        max(1, $paidCycles)
                                    )
                            )
                            : max(1, $paidCycles);
                        // Existing service.price is a durable effective
                        // recurring obligation, but it is not proof of what
                        // was prepaid in this period (the initial invoice may
                        // include setup and a first-cycle coupon). Baseline
                        // refundable value at zero and block upgrades until
                        // the next period boundary establishes a clean renewal
                        // ledger.
                        DB::table('services')
                            ->where('id', $service->id)
                            ->whereNull('current_period_price')
                            ->update([
                                'period_base_price' => '0.00',
                                'current_period_price' => number_format(
                                    max(
                                        0,
                                        (float) ($service->price ?? 0)
                                    ),
                                    2,
                                    '.',
                                    ''
                                ),
                                'pricing_ledger_started_at' => null,
                                'pricing_ledger_verified_at' => null,
                                'billing_cycles_completed' => $completedCycles,
                            ]);
                    }
                });
        }, 5);
    }

    public function down(): void
    {
        // The schema migration owns these columns. Backfilled pricing evidence
        // is intentionally retained until that guarded schema rollback.
    }
};
