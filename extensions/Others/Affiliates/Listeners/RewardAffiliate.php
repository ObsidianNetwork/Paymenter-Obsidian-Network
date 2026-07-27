<?php

namespace Paymenter\Extensions\Others\Affiliates\Listeners;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Invoice\CreditInvoicePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Others\Affiliates\Models\Affiliate;
use Paymenter\Extensions\Others\Affiliates\Models\AffiliateOrder;
use Paymenter\Extensions\Others\Affiliates\Models\AffiliateReward;
use Paymenter\Extensions\Others\Affiliates\Services\AffiliateRewardCalculator;

class RewardAffiliate
{
    /**
     * Create the event listener.
     */
    public function __construct(
        private CreditInvoicePaymentService $creditPayments,
        private AffiliateRewardCalculator $calculator
    ) {}

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        if (
            !isset($event->invoice)
            || !$event->invoice instanceof Invoice
            || !$event->invoice->exists
        ) {
            return;
        }
        if (!Schema::hasTable('ext_affiliate_rewards')) {
            throw new \RuntimeException(
                'Affiliate rewards are not ready. Run php artisan migrate before accepting payments.'
            );
        }

        $invoiceId = (int) $event->invoice->getKey();

        DB::transaction(function () use ($invoiceId): void {
            $invoice = Invoice::query()
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->first();
            if (
                $invoice === null
                || $invoice->status !== Invoice::STATUS_PAID
                || AffiliateReward::query()
                    ->where('invoice_id', $invoice->id)
                    ->exists()
            ) {
                return;
            }

            // The first item determines whether this is an order/service
            // invoice, preserving the extension's existing reward contract.
            $firstItem = $invoice->items()
                ->orderBy('id')
                ->first();
            if (
                $firstItem === null
                || $firstItem->reference_type !== Service::class
                || $firstItem->reference_id === null
            ) {
                return;
            }

            // Payment fulfillment already follows invoice -> service -> item.
            // Keep that same order for direct event replays, then re-read the
            // item under lock so a stale relationship can never be rewarded.
            $service = Service::query()
                ->whereKey($firstItem->reference_id)
                ->lockForUpdate()
                ->first();
            $lockedItem = $invoice->items()
                ->whereKey($firstItem->id)
                ->lockForUpdate()
                ->first();
            if (
                $service === null
                || $service->order_id === null
                || $lockedItem === null
                || $lockedItem->reference_type !== Service::class
                || (int) $lockedItem->reference_id !== (int) $service->id
            ) {
                return;
            }

            $referralSnapshot = AffiliateOrder::query()
                ->where('order_id', $service->order_id)
                ->first();
            if ($referralSnapshot === null) {
                return;
            }

            /** @var Affiliate|null $affiliate */
            $affiliate = Affiliate::query()
                ->whereKey($referralSnapshot->affiliate_id)
                ->lockForUpdate()
                ->first();
            $referral = AffiliateOrder::query()
                ->whereKey($referralSnapshot->id)
                ->lockForUpdate()
                ->first();
            if (
                $affiliate === null
                || $affiliate->user_id === null
                || $referral === null
                || (int) $referral->order_id !== (int) $service->order_id
                || (int) $referral->affiliate_id !== (int) $affiliate->id
            ) {
                return;
            }

            $defaultReward = $affiliate->reward === null
                ? ExtensionHelper::getExtension(
                    'other',
                    'Affiliates'
                )->config('default_reward')
                : null;
            $percentage = $this->calculator->percentage(
                $affiliate->reward,
                $defaultReward
            );
            $amount = $this->calculator->rewardAmount(
                $invoice->items()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'price', 'quantity']),
                $percentage
            );
            $currencyCode = $this->calculator->currencyCode(
                $invoice->currency_code
            );

            AffiliateReward::query()->create([
                'invoice_id' => $invoice->id,
                'affiliate_order_id' => $referral->id,
                'affiliate_id' => $affiliate->id,
                'user_id' => $affiliate->user_id,
                'currency_code' => $currencyCode,
                'reward_percentage' => $percentage,
                'amount' => $amount,
            ]);

            if ($amount !== '0.00') {
                $this->creditPayments->addBalance(
                    (int) $affiliate->user_id,
                    $currencyCode,
                    $amount
                );
            }
        }, 5);
    }
}
