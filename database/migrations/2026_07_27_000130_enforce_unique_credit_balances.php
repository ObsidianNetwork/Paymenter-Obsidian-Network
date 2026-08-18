<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'credits_user_currency_unique';

    private const USER_INDEX = 'credits_user_id_index';

    private const MAX_CENTS = 99_999_999_999_999_999;

    public function up(): void
    {
        if (!Schema::hasTable('credits')) {
            return;
        }

        if (
            in_array(
                DB::connection()->getDriverName(),
                ['mysql', 'mariadb'],
                true
            )
        ) {
            // MariaDB/MySQL DDL commits transactions implicitly. Hold a table
            // write lock from consolidation until ALTER TABLE has acquired its
            // metadata lock, so live credit writes cannot recreate a duplicate
            // in the otherwise-unprotected commit-to-DDL gap.
            DB::statement('LOCK TABLES `credits` WRITE');
            try {
                $this->consolidateDuplicates();
                $this->addUniqueIndex();
            } finally {
                DB::statement('UNLOCK TABLES');
            }

            return;
        }

        // SQLite and PostgreSQL support transactional index creation, keeping
        // consolidation and the uniqueness constraint atomic.
        DB::transaction(function (): void {
            $this->consolidateDuplicates();
            $this->addUniqueIndex();
        }, 5);
    }

    public function down(): void
    {
        if (!Schema::hasTable('credits')) {
            return;
        }

        // InnoDB may discard its implicit user_id index after the composite
        // unique index is added, then use that unique index to support the
        // foreign key. Restore a dedicated FK index before dropping it.
        if (!Schema::hasIndex('credits', self::USER_INDEX)) {
            Schema::table('credits', function (Blueprint $table): void {
                $table->index('user_id', self::USER_INDEX);
            });
        }

        Schema::table('credits', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
        });
    }

    private function decimalToCents(mixed $value): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw new RuntimeException(
                    'Stored credit balances must be finite decimals.'
                );
            }
            $value = number_format($value, 2, '.', '');
        }

        if (
            !is_string($value)
            || preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $value) !== 1
        ) {
            throw new RuntimeException(
                'Stored credit balances must use at most two decimal places.'
            );
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');
        $cents = ((int) $whole * 100) + (int) $fraction;

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

    private function consolidateDuplicates(): void
    {
        $duplicates = DB::table('credits')
            ->select(['user_id', 'currency_code'])
            ->groupBy('user_id', 'currency_code')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('user_id')
            ->orderBy('currency_code')
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table('credits')
                ->where('user_id', $duplicate->user_id)
                ->where('currency_code', $duplicate->currency_code)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'amount']);
            $totalCents = $rows->reduce(
                fn (int $total, object $row): int => $this->checkedAdd(
                    $total,
                    $this->decimalToCents($row->amount)
                ),
                0
            );
            if ($totalCents < 0 || $totalCents > self::MAX_CENTS) {
                throw new RuntimeException(
                    "Credit balance for user {$duplicate->user_id} in {$duplicate->currency_code} cannot be consolidated without exceeding DECIMAL(17,2)."
                );
            }

            $keeper = $rows->first();
            DB::table('credits')
                ->where('id', $keeper->id)
                ->update([
                    'amount' => $this->centsToDecimal($totalCents),
                    'updated_at' => now(),
                ]);
            DB::table('credits')
                ->whereIn('id', $rows->skip(1)->pluck('id'))
                ->delete();
        }
    }

    private function addUniqueIndex(): void
    {
        Schema::table('credits', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'currency_code'],
                self::UNIQUE_INDEX
            );
        });
    }

    private function checkedAdd(int $left, int $right): int
    {
        if (
            ($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new RuntimeException(
                'Consolidated credit balance exceeds the supported range.'
            );
        }

        return $left + $right;
    }
};
