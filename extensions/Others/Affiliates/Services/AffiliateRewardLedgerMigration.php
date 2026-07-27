<?php

namespace Paymenter\Extensions\Others\Affiliates\Services;

use App\Models\Extension;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Others\Affiliates\Models\Affiliate;
use Paymenter\Extensions\Others\Affiliates\Models\AffiliateOrder;

class AffiliateRewardLedgerMigration
{
    public static function migrate(): void
    {
        if (
            !Schema::hasTable('ext_affiliates')
            || !Schema::hasTable('ext_affiliate_orders')
        ) {
            return;
        }

        if (!Schema::hasTable('ext_affiliate_rewards')) {
            self::createLedger();
        }

        self::backfill();
        self::assertReady();
    }

    public static function rollback(): void
    {
        Schema::dropIfExists('ext_affiliate_rewards');
    }

    /**
     * Freeze historical reward evidence without crediting it again.
     *
     * The legacy listener already deposited these rewards. Backfill uses the
     * same percentage the legacy earnings UI displayed at migration time,
     * preventing a redelivered historical InvoicePaid event from paying twice.
     */
    public static function backfill(): void
    {
        if (!Schema::hasTable('ext_affiliate_rewards')) {
            throw new \RuntimeException(
                'The affiliate reward ledger migration has not been applied.'
            );
        }

        $defaultReward = self::defaultReward();
        self::eligibleInvoiceIds()
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('ext_affiliate_rewards')
                    ->whereColumn(
                        'ext_affiliate_rewards.invoice_id',
                        'invoices.id'
                    );
            })
            ->chunkById(100, function ($invoices) use (
                $defaultReward
            ): void {
                foreach ($invoices as $invoiceSnapshot) {
                    DB::transaction(function () use (
                        $invoiceSnapshot,
                        $defaultReward
                    ): void {
                        self::backfillInvoice(
                            (int) $invoiceSnapshot->id,
                            $defaultReward
                        );
                    }, 5);
                }
            }, 'invoices.id', 'id');
    }

    public static function assertReady(): void
    {
        if (!Schema::hasTable('ext_affiliate_rewards')) {
            throw new \RuntimeException(
                'Affiliate rewards are not ready. Run php artisan migrate before accepting payments.'
            );
        }

        $missingInvoice = self::eligibleInvoiceIds()
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('ext_affiliate_rewards')
                    ->whereColumn(
                        'ext_affiliate_rewards.invoice_id',
                        'invoices.id'
                    );
            })
            ->orderBy('invoices.id')
            ->value('invoices.id');
        if ($missingInvoice !== null) {
            throw new \RuntimeException(
                "Paid affiliate invoice {$missingInvoice} has no immutable reward evidence."
            );
        }
    }

    private static function createLedger(): void
    {
        Schema::create('ext_affiliate_rewards', function (
            Blueprint $table
        ): void {
            $table->id();
            $table->foreignIdFor(Invoice::class)
                ->constrained()
                ->restrictOnDelete();
            $table->unique('invoice_id');
            $table->foreignIdFor(
                AffiliateOrder::class,
                'affiliate_order_id'
            )
                ->constrained('ext_affiliate_orders')
                ->restrictOnDelete();
            $table->foreignIdFor(Affiliate::class)
                ->constrained('ext_affiliates')
                ->restrictOnDelete();
            $table->foreignIdFor(User::class)
                ->constrained()
                ->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->unsignedTinyInteger('reward_percentage');
            $table->decimal('amount', 17, 2);
            $table->timestamps();
        });
    }

    private static function backfillInvoice(
        int $invoiceId,
        mixed $defaultReward
    ): void {
        $invoice = DB::table('invoices')
            ->where('id', $invoiceId)
            ->where('status', Invoice::STATUS_PAID)
            ->lockForUpdate()
            ->first(['id', 'currency_code', 'updated_at']);
        if (
            $invoice === null
            || DB::table('ext_affiliate_rewards')
                ->where('invoice_id', $invoiceId)
                ->exists()
        ) {
            return;
        }

        $firstItem = DB::table('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->orderBy('id')
            ->first(['id', 'reference_type', 'reference_id']);
        if (
            $firstItem === null
            || $firstItem->reference_type !== Service::class
            || $firstItem->reference_id === null
        ) {
            return;
        }

        $service = DB::table('services')
            ->where('id', $firstItem->reference_id)
            ->lockForUpdate()
            ->first(['id', 'order_id']);
        if ($service === null || $service->order_id === null) {
            return;
        }

        $referral = DB::table('ext_affiliate_orders')
            ->join(
                'ext_affiliates',
                'ext_affiliates.id',
                '=',
                'ext_affiliate_orders.affiliate_id'
            )
            ->where('ext_affiliate_orders.order_id', $service->order_id)
            ->lockForUpdate()
            ->first([
                'ext_affiliate_orders.id as affiliate_order_id',
                'ext_affiliate_orders.affiliate_id',
                'ext_affiliates.user_id',
                'ext_affiliates.reward',
            ]);
        if ($referral === null || $referral->user_id === null) {
            return;
        }

        $calculator = new AffiliateRewardCalculator;
        // The legacy listener used a truthy fallback, so an explicit stored
        // zero historically paid the then-current default. Preserve that
        // behavior only while freezing already-deposited reward evidence.
        $historicalReward = $referral->reward ?: $defaultReward;
        $percentage = $calculator->percentage(
            $historicalReward,
            null
        );
        $amount = $calculator->rewardAmount(
            DB::table('invoice_items')
                ->where('invoice_id', $invoiceId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['price', 'quantity']),
            $percentage
        );

        DB::table('ext_affiliate_rewards')->insertOrIgnore([
            'invoice_id' => $invoiceId,
            'affiliate_order_id' => $referral->affiliate_order_id,
            'affiliate_id' => $referral->affiliate_id,
            'user_id' => $referral->user_id,
            'currency_code' => $calculator->currencyCode(
                $invoice->currency_code
            ),
            'reward_percentage' => $percentage,
            'amount' => $amount,
            'created_at' => $invoice->updated_at ?? now(),
            'updated_at' => $invoice->updated_at ?? now(),
        ]);
    }

    private static function defaultReward(): mixed
    {
        $extensionQuery = DB::table('extensions')
            ->where('extension', 'Affiliates')
            ->where('type', 'other');
        if (Schema::hasColumn('extensions', 'deleted_at')) {
            $extensionQuery->whereNull('deleted_at');
        }
        $extensionId = $extensionQuery
            ->orderBy('id')
            ->value('id');
        if ($extensionId === null) {
            return null;
        }

        return DB::table('settings')
            ->where('settingable_type', Extension::class)
            ->where('settingable_id', $extensionId)
            ->where('key', 'default_reward')
            ->value('value');
    }

    private static function eligibleInvoiceIds(): Builder
    {
        return DB::table('invoices')
            ->join(
                'invoice_items as first_item',
                'first_item.invoice_id',
                '=',
                'invoices.id'
            )
            ->join(
                'services',
                'services.id',
                '=',
                'first_item.reference_id'
            )
            ->join(
                'ext_affiliate_orders',
                'ext_affiliate_orders.order_id',
                '=',
                'services.order_id'
            )
            ->where('invoices.status', Invoice::STATUS_PAID)
            ->where('first_item.reference_type', Service::class)
            ->whereRaw(
                'first_item.id = (SELECT MIN(ii.id) FROM invoice_items ii WHERE ii.invoice_id = invoices.id)'
            )
            ->select('invoices.id')
            ->distinct();
    }
}
