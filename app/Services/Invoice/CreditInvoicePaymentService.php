<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class CreditInvoicePaymentService
{
    private const MAX_DECIMAL_CENTS = 99_999_999_999_999_999;

    /**
     * Apply the invoice owner's credit without allowing another settlement path
     * to observe or mutate a stale remaining balance.
     *
     * @return array{invoice: Invoice, applied: string, fully_paid: bool}
     */
    public function pay(
        Invoice|int $invoice,
        bool $allowPartial = true
    ): array {
        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : $invoice;

        return DB::transaction(function () use (
            $invoiceId,
            $allowPartial
        ): array {
            $invoice = Invoice::query()
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->status !== Invoice::STATUS_PENDING) {
                return $this->result($invoice, 0);
            }

            // Credit must remain the final shared row lock. A succeeded payment
            // synchronously commits services and upgrades, whose established
            // order is invoice -> services -> upgrades -> reservations -> items.
            $scope = app(ProcessPaidInvoiceService::class)
                ->lockFulfillmentObligations($invoice);

            app(CapacityInvoicePaymentService::class)
                ->assertPaymentAttemptAllowed($invoice);

            $totalCents = $scope['items']->reduce(
                fn (int $total, $item): int => $this->checkedAdd(
                    $total,
                    $this->checkedMultiply(
                        $this->decimalToCents($item->price),
                        (int) $item->quantity
                    )
                ),
                0
            );
            $paidCents = $invoice->transactions()
                ->where(
                    'status',
                    InvoiceTransactionStatus::Succeeded->value
                )
                ->orderBy('id')
                ->get(['id', 'amount'])
                ->reduce(
                    fn (int $total, $transaction): int => $this->checkedAdd(
                        $total,
                        $this->decimalToCents($transaction->amount)
                    ),
                    0
                );
            $remainingCents = max(
                0,
                $this->checkedAdd($totalCents, -$paidCents)
            );
            if ($remainingCents === 0 || $invoice->user_id === null) {
                return $this->result($invoice, 0);
            }

            $credit = Credit::query()
                ->where('user_id', $invoice->user_id)
                ->where('currency_code', $invoice->currency_code)
                ->lockForUpdate()
                ->first();
            if ($credit === null) {
                return $this->result($invoice, 0);
            }

            $creditCents = $this->decimalToCents($credit->amount);
            if (
                $creditCents <= 0
                || (!$allowPartial && $creditCents < $remainingCents)
            ) {
                return $this->result($invoice, 0);
            }

            $appliedCents = min($creditCents, $remainingCents);
            $credit->amount = $this->centsToDecimal(
                $creditCents - $appliedCents
            );
            $credit->save();

            ExtensionHelper::addPayment(
                $invoice,
                null,
                amount: $this->centsToDecimal($appliedCents),
                isCreditTransaction: true
            );

            $result = $this->result($invoice, $appliedCents);
            if (
                $appliedCents === $remainingCents
                && !$result['fully_paid']
            ) {
                // Unlike gateway evidence, account credit is still reversible
                // here. Roll the debit and transaction back instead of creating
                // a manual-refund obligation for an internal balance.
                throw new DisplayException(
                    'Credits could not be applied because this invoice is not currently payable.'
                );
            }

            return $result;
        }, 5);
    }

    /**
     * Add balance while tolerating two first-credit writers racing the unique
     * user/currency key. Callers must acquire their invoice/service locks first.
     */
    public function addBalance(
        int $userId,
        string $currencyCode,
        mixed $amount
    ): Credit {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException(
                'Credit balance changes require an existing database transaction.'
            );
        }

        $amountCents = $this->decimalToCents($amount);
        if ($amountCents < 0) {
            throw new \RuntimeException(
                'Credit balance additions cannot be negative.'
            );
        }

        $currencyCode = strtoupper(trim($currencyCode));
        if (preg_match('/^[A-Z]{3}$/D', $currencyCode) !== 1) {
            throw new \RuntimeException(
                'Credit balance currency codes must contain three letters.'
            );
        }
        $now = now();
        DB::table('credits')->insertOrIgnore([
            'user_id' => $userId,
            'currency_code' => $currencyCode,
            'amount' => '0.00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $credit = Credit::query()
            ->where('user_id', $userId)
            ->where('currency_code', $currencyCode)
            ->lockForUpdate()
            ->firstOrFail();
        $updatedCents = $this->checkedAdd(
            $this->decimalToCents($credit->amount),
            $amountCents
        );
        if (
            $updatedCents < 0
            || $updatedCents > self::MAX_DECIMAL_CENTS
        ) {
            throw new \RuntimeException(
                'The updated credit balance exceeds DECIMAL(17,2).'
            );
        }
        $credit->amount = $this->centsToDecimal($updatedCents);
        $credit->save();

        return $credit->fresh();
    }

    /**
     * @return array{invoice: Invoice, applied: string, fully_paid: bool}
     */
    private function result(Invoice $invoice, int $appliedCents): array
    {
        $invoice = Invoice::query()
            ->with(['items', 'transactions'])
            ->findOrFail($invoice->id);

        return [
            'invoice' => $invoice,
            'applied' => $this->centsToDecimal($appliedCents),
            'fully_paid' => $invoice->status === Invoice::STATUS_PAID,
        ];
    }

    private function decimalToCents(mixed $value): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw new \RuntimeException(
                    'Credit and invoice amounts must be finite decimals.'
                );
            }
            $value = number_format($value, 2, '.', '');
        }

        if (
            !is_string($value)
            || preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $value) !== 1
        ) {
            throw new \RuntimeException(
                'Credit and invoice amounts must use at most two decimal places.'
            );
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 15) {
            throw new \RuntimeException(
                'Credit and invoice amounts exceed the supported range.'
            );
        }

        $cents = ((int) $whole * 100) + (int) $fraction;
        if ($cents > self::MAX_DECIMAL_CENTS) {
            throw new \RuntimeException(
                'Credit and invoice amounts exceed DECIMAL(17,2).'
            );
        }

        return $negative ? -$cents : $cents;
    }

    private function centsToDecimal(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);

        return ($negative ? '-' : '')
            . intdiv($absolute, 100)
            . '.'
            . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function checkedAdd(int $left, int $right): int
    {
        if (
            ($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \RuntimeException(
                'Credit and invoice totals exceed the supported range.'
            );
        }

        return $left + $right;
    }

    private function checkedMultiply(int $amount, int $quantity): int
    {
        $result = $amount * $quantity;
        if (!is_int($result)) {
            throw new \RuntimeException(
                'Invoice line totals exceed the supported range.'
            );
        }

        return $result;
    }
}
