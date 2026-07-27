<?php

use Paymenter\Extensions\Others\Affiliates\Services\AffiliateRewardLedgerMigration;

return static function (): void {
    AffiliateRewardLedgerMigration::assertReady();
};
