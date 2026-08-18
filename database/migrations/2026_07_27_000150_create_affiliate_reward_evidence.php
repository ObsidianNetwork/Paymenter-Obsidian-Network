<?php

use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Others\Affiliates\Services\AffiliateRewardLedgerMigration;

return new class extends Migration
{
    public function up(): void
    {
        // Existing bundled Affiliates installations do not automatically run
        // extension-path migrations during a normal Paymenter deployment.
        AffiliateRewardLedgerMigration::migrate();
    }

    public function down(): void
    {
        // The core bridge does not own extension lifecycle data. Keeping the
        // ledger also makes a Paymenter rollback safe after new rewards exist.
    }
};
