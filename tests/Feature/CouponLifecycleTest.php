<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CouponLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_used_coupon_cannot_be_deleted_through_the_model(): void
    {
        [$coupon] = $this->usedCoupon();

        try {
            $coupon->delete();
            $this->fail(
                'A coupon referenced by service billing history was deleted.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'billing history',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
        $this->assertDatabaseHas('services', [
            'coupon_id' => $coupon->id,
        ]);
    }

    public function test_foreign_key_blocks_direct_used_coupon_deletion(): void
    {
        [$coupon] = $this->usedCoupon();

        $this->expectException(QueryException::class);

        DB::table('coupons')
            ->where('id', $coupon->id)
            ->delete();
    }

    public function test_unused_coupon_can_be_deleted(): void
    {
        $coupon = $this->coupon();

        $this->assertTrue((bool) $coupon->delete());
        $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
    }

    /**
     * @return array{Coupon, Service}
     */
    private function usedCoupon(): array
    {
        $coupon = $this->coupon();
        $service = Service::factory()->create([
            'coupon_id' => $coupon->id,
            'user_id' => User::factory()->create()->id,
        ]);

        return [$coupon, $service];
    }

    private function coupon(): Coupon
    {
        return Coupon::create([
            'type' => 'fixed',
            'applies_to' => 'price',
            'recurring' => 2,
            'code' => fake()->unique()->bothify('FINITE-####'),
            'value' => 5,
        ]);
    }
}
