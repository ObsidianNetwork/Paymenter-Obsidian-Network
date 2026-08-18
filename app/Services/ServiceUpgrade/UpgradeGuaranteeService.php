<?php

namespace App\Services\ServiceUpgrade;

use App\Exceptions\DisplayException;
use App\Models\Service;
use Carbon\CarbonInterface;

final class UpgradeGuaranteeService
{
    public const GUARANTEE_DAYS = 7;

    public function deadline(
        Service $service,
        ?CarbonInterface $from = null
    ): CarbonInterface {
        $deadline = ($from ?? now())->copy()->addDays(
            self::GUARANTEE_DAYS
        );

        if (
            $service->expires_at !== null
            && $service->expires_at->lessThan($deadline)
        ) {
            throw new DisplayException(
                'Renew this service before requesting an upgrade. It must remain active for the full seven-day capacity guarantee.'
            );
        }

        return $deadline;
    }
}
