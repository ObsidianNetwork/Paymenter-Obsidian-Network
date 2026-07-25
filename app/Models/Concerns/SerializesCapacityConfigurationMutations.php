<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Keep the model event guard and the SQL mutation in one transaction.
 *
 * CapacityConfigurationMutationGuard locks the affected product before it
 * either validates a destructive change or admits an ordinary versioned
 * change. Without this wrapper, Eloquent's saving event would run before the
 * UPDATE statement and a quote could slip between the lock and the write.
 */
trait SerializesCapacityConfigurationMutations
{
    public function save(array $options = [])
    {
        if (DB::transactionLevel() > 0) {
            return parent::save($options);
        }

        return DB::transaction(
            fn () => parent::save($options),
            5
        );
    }

    public function delete()
    {
        if (DB::transactionLevel() > 0) {
            return parent::delete();
        }

        return DB::transaction(
            fn () => parent::delete(),
            5
        );
    }
}
