<?php

namespace Paymenter\Extensions\Others\Affiliates\Services;

class AffiliateRewardCalculator
{
    /**
     * @param  iterable<object{price: mixed, quantity: mixed}>  $items
     */
    public function rewardAmount(
        iterable $items,
        int $percentage
    ): string {
        if ($percentage < 0 || $percentage > 100) {
            throw new \RuntimeException(
                'Affiliate reward percentages must be between 0 and 100.'
            );
        }

        $totalCents = 0;
        foreach ($items as $item) {
            $lineCents = $this->checkedMultiply(
                $this->decimalToCents($item->price),
                (int) $item->quantity
            );
            $totalCents = $this->checkedAdd($totalCents, $lineCents);
        }
        $totalCents = max(0, $totalCents);

        // Divide before multiplying so a valid DECIMAL(17,2) invoice cannot
        // overflow a 64-bit integer merely while applying a percentage.
        $rewardCents = $this->checkedAdd(
            $this->checkedMultiply(
                intdiv($totalCents, 100),
                $percentage
            ),
            intdiv(
                (($totalCents % 100) * $percentage) + 50,
                100
            )
        );

        return $this->centsToDecimal($rewardCents);
    }

    public function percentage(
        mixed $affiliateReward,
        mixed $defaultReward
    ): int {
        $configured = $affiliateReward ?? $defaultReward;
        if (
            !is_int($configured)
            && (
                !is_string($configured)
                || preg_match('/^\d+$/D', $configured) !== 1
            )
        ) {
            throw new \RuntimeException(
                'Affiliate reward percentages must be whole numbers.'
            );
        }

        $percentage = (int) $configured;
        if ($percentage < 0 || $percentage > 100) {
            throw new \RuntimeException(
                'Affiliate reward percentages must be between 0 and 100.'
            );
        }

        return $percentage;
    }

    public function currencyCode(mixed $currencyCode): string
    {
        if (!is_string($currencyCode)) {
            throw new \RuntimeException(
                'Affiliate reward currencies must contain three letters.'
            );
        }

        $currencyCode = strtoupper(trim($currencyCode));
        if (preg_match('/^[A-Z]{3}$/D', $currencyCode) !== 1) {
            throw new \RuntimeException(
                'Affiliate reward currencies must contain three letters.'
            );
        }

        return $currencyCode;
    }

    /**
     * Preserve the extension's public currency-name/float response contract
     * while aggregating immutable decimal evidence in exact cents.
     *
     * @param  iterable<object>  $rewards
     * @return array<string, float>
     */
    public function summarize(iterable $rewards): array
    {
        $totals = [];
        foreach ($rewards as $reward) {
            $currencyCode = $this->currencyCode(
                $reward->currency_code
            );
            $currency = $reward->currency?->name ?? $currencyCode;
            $amountCents = $this->decimalToCents($reward->amount);
            if ($amountCents < 0) {
                throw new \RuntimeException(
                    'Affiliate reward evidence cannot contain negative amounts.'
                );
            }
            $totals[$currency] = $this->checkedAdd(
                $totals[$currency] ?? 0,
                $amountCents
            );
        }
        ksort($totals);

        return array_map(
            fn (int $cents): float => round($cents / 100, 2),
            $totals
        );
    }

    private function decimalToCents(mixed $value): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw new \RuntimeException(
                    'Affiliate reward amounts must be finite decimals.'
                );
            }
            $value = number_format($value, 2, '.', '');
        }

        if (
            !is_string($value)
            || preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $value) !== 1
        ) {
            throw new \RuntimeException(
                'Affiliate reward amounts must use at most two decimal places.'
            );
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(
            explode('.', $unsigned, 2),
            2,
            ''
        );
        $fraction = str_pad($fraction, 2, '0');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 15) {
            throw new \RuntimeException(
                'Affiliate reward amounts exceed DECIMAL(17,2).'
            );
        }

        $cents = ((int) $whole * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    private function centsToDecimal(int $cents): string
    {
        if ($cents < 0) {
            throw new \RuntimeException(
                'Affiliate reward amounts cannot be negative.'
            );
        }

        return intdiv($cents, 100)
            . '.'
            . str_pad(
                (string) ($cents % 100),
                2,
                '0',
                STR_PAD_LEFT
            );
    }

    private function checkedAdd(int $left, int $right): int
    {
        if (
            ($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \RuntimeException(
                'Affiliate reward totals exceed the supported range.'
            );
        }

        return $left + $right;
    }

    private function checkedMultiply(int $amount, int $quantity): int
    {
        $result = $amount * $quantity;
        if (!is_int($result)) {
            throw new \RuntimeException(
                'Affiliate reward totals exceed the supported range.'
            );
        }

        return $result;
    }
}
