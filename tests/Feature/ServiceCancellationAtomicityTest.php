<?php

namespace Tests\Feature;

use App\Jobs\Server\CreateJob;
use App\Livewire\Services\Cancel;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\Service\ServiceJobDispatchService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ServiceCancellationAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_failure_rolls_back_cancellation_and_create_remains_recoverable(): void
    {
        $fixture = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'price' => '10.00',
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
        ]);
        $invoice->items()->create([
            'description' => 'Initial service',
            'quantity' => 1,
            'price' => '10.00',
            'reference_type' => Service::class,
            'reference_id' => $service->id,
        ]);

        Queue::shouldReceive('push')
            ->once()
            ->andThrow(new RuntimeException('Queue broker unavailable.'));
        $dispatch = app(ServiceJobDispatchService::class)
            ->requestCreate($service);
        $this->mock(CancelInvoiceService::class)
            ->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException(
                'Cancellation coordinator failed.'
            ));

        $this->actingAs($user);
        try {
            Livewire::test(Cancel::class, ['service' => $service])
                ->set('type', 'immediate')
                ->set('reason', 'Atomic cancellation regression')
                ->call('cancelService');
            $this->fail('The cancellation listener failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Cancellation coordinator failed.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing('service_cancellations', [
            'service_id' => $service->id,
        ]);
        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
        $this->assertDatabaseHas('service_job_dispatches', [
            'id' => $dispatch->id,
            'service_id' => $service->id,
            'action' => ServiceJobDispatchService::ACTION_CREATE,
            'dispatch_token' => $dispatch->dispatch_token,
        ]);

        $this->travel(2)->minutes();
        Queue::fake();
        $summary = app(ServiceJobDispatchService::class)->recover();

        $this->assertSame(1, $summary['dispatched']);
        Queue::assertPushed(CreateJob::class, 1);

        $pushed = Queue::pushed(CreateJob::class)->first();
        (new UniqueLock(app('cache')->store()))->release($pushed);
    }
}
