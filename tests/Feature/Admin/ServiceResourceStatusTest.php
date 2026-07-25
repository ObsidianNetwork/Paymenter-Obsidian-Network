<?php

namespace Tests\Feature\Admin;

use App\Admin\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceResourceStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_fulfillment_status_has_an_operator_label_and_safe_badge_color(): void
    {
        $statuses = [
            Service::STATUS_PENDING,
            Service::STATUS_PROVISIONING,
            Service::STATUS_PROVISIONING_FAILED,
            Service::STATUS_ACTIVE,
            Service::STATUS_CANCELLATION_PENDING,
            Service::STATUS_SUSPENDED,
            Service::STATUS_CANCELLED,
        ];

        foreach ($statuses as $status) {
            $this->assertArrayHasKey($status, ServiceResource::statusOptions());
            $this->assertNotSame('', ServiceResource::statusColor($status));
        }

        $this->assertSame('gray', ServiceResource::statusColor('future_status'));
    }

    public function test_navigation_badge_surfaces_all_nonterminal_operator_work(): void
    {
        Service::withoutEvents(function (): void {
            Service::query()->delete();
            foreach ([
                Service::STATUS_PENDING,
                Service::STATUS_PROVISIONING,
                Service::STATUS_PROVISIONING_FAILED,
                Service::STATUS_CANCELLATION_PENDING,
                Service::STATUS_ACTIVE,
                Service::STATUS_CANCELLED,
            ] as $status) {
                Service::factory()->create(['status' => $status]);
            }
        });

        $this->assertSame('4', ServiceResource::getNavigationBadge());
        $this->assertSame('danger', ServiceResource::getNavigationBadgeColor());
    }
}
