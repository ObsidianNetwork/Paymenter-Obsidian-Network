<?php

use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Others\Affiliates\Services\AffiliateRewardLedgerMigration;

return new class extends Migration
{
    public function up(): void
    {
        AffiliateRewardLedgerMigration::migrate();
    }

    public function down(): void
    {
        AffiliateRewardLedgerMigration::rollback();
    }
};
