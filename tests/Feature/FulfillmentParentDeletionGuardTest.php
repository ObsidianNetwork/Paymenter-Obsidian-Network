<?php

namespace Tests\Feature;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FulfillmentParentDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_model_deletion_cannot_cascade_past_service_guards(): void
    {
        [$user, $order, $service] = $this->fulfilledOrder();

        try {
            $order->delete();
            $this->fail('An order with fulfillment history was deleted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'fulfillment history',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_database_rejects_raw_order_deletion_with_services(): void
    {
        [, $order, $service] = $this->fulfilledOrder();

        try {
            DB::table('orders')->where('id', $order->id)->delete();
            $this->fail('The database allowed an order cascade.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('services', ['id' => $service->id]);
    }

    public function test_user_model_deletion_retains_billing_history(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id]);

        try {
            $user->delete();
            $this->fail('A user with billing history was deleted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'billing, wallet, or fulfillment history',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_database_rejects_raw_user_deletion_with_invoices(): void
    {
        $user = User::factory()->create();
        Invoice::factory()->create(['user_id' => $user->id]);

        try {
            DB::table('users')->where('id', $user->id)->delete();
            $this->fail('The database allowed a user billing cascade.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_user_model_deletion_retains_wallet_balance(): void
    {
        $user = User::factory()->create();
        $credit = Credit::query()->create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'amount' => '12.34',
        ]);

        try {
            $user->delete();
            $this->fail('A user with wallet value was deleted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'billing, wallet, or fulfillment history',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('credits', [
            'id' => $credit->id,
            'user_id' => $user->id,
            'amount' => 12.34,
        ]);
    }

    public function test_database_rejects_raw_user_deletion_with_wallet_balance(): void
    {
        $user = User::factory()->create();
        $credit = Credit::query()->create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'amount' => '12.34',
        ]);

        try {
            DB::table('users')->where('id', $user->id)->delete();
            $this->fail('The database allowed a wallet cascade.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('credits', [
            'id' => $credit->id,
            'user_id' => $user->id,
            'amount' => 12.34,
        ]);
    }

    private function fulfilledOrder(): array
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $order = Order::create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
        ]);
        $service = Service::factory()->create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'currency_code' => 'USD',
        ]);

        return [$user, $order, $service];
    }
}
