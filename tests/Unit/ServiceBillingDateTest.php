<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ServiceBillingDateTest extends TestCase
{
    public function test_monthly_due_date_does_not_skip_february(): void
    {
        $service = $this->service('month', 1);

        $due = $service->calculateNextDueDate(
            CarbonImmutable::parse('2027-01-31 12:30:00')
        );

        $this->assertSame(
            '2027-02-28 12:30:00',
            $due->format('Y-m-d H:i:s')
        );
    }

    public function test_annual_due_date_clamps_a_leap_day(): void
    {
        $service = $this->service('year', 1);

        $due = $service->calculateNextDueDate(
            CarbonImmutable::parse('2028-02-29 08:00:00')
        );

        $this->assertSame(
            '2029-02-28 08:00:00',
            $due->format('Y-m-d H:i:s')
        );
    }

    public function test_invoice_description_uses_the_same_non_overflowing_period(): void
    {
        $service = $this->service('month', 1);
        $service->expires_at = CarbonImmutable::parse(
            '2027-01-31 12:30:00'
        );

        $this->assertSame(
            'Test service (Jan 31, 2027 - Feb 28, 2027)',
            $service->description
        );
    }

    private function service(string $unit, int $period): Service
    {
        $plan = new Plan([
            'type' => 'recurring',
            'billing_unit' => $unit,
            'billing_period' => $period,
        ]);
        $service = new Service([
            'status' => Service::STATUS_ACTIVE,
        ]);
        $service->setRelation('plan', $plan);
        $service->setRelation(
            'product',
            new Product(['name' => 'Test service'])
        );

        return $service;
    }
}
