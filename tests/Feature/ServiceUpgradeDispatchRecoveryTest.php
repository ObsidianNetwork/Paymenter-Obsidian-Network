<?php

namespace Tests\Feature;

use App\Jobs\Server\UpgradeJob;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeDispatchRecoveryService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ServiceUpgradeDispatchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_dispatches_only_durable_dispatchable_states(): void
    {
        Queue::fake();
        $paid = $this->createUpgrade(
            ServiceUpgrade::STATUS_PAID_COMMITTED
        );
        $retryable = $this->createUpgrade(
            ServiceUpgrade::STATUS_RETRYABLE_FAILED
        );
        $provisioning = $this->createUpgrade(
            ServiceUpgrade::STATUS_PROVISIONING
        );
        $attention = $this->createUpgrade(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION
        );

        $summary = app(
            ServiceUpgradeDispatchRecoveryService::class
        )->recover();

        $this->assertSame([
            'scanned' => 2,
            'dispatched' => 2,
            'skipped' => 0,
            'failed' => 0,
        ], $summary);
        Queue::assertPushed(UpgradeJob::class, 2);
        Queue::assertPushed(
            UpgradeJob::class,
            fn (UpgradeJob $job): bool => (int) $job->serviceUpgrade->id === (int) $paid->id
        );
        Queue::assertPushed(
            UpgradeJob::class,
            fn (UpgradeJob $job): bool => (int) $job->serviceUpgrade->id === (int) $retryable->id
        );
        Queue::assertNotPushed(
            UpgradeJob::class,
            fn (UpgradeJob $job): bool => in_array(
                (int) $job->serviceUpgrade->id,
                [(int) $provisioning->id, (int) $attention->id],
                true
            )
        );

        $uniqueLock = new UniqueLock(app('cache')->store());
        $uniqueLock->release(new UpgradeJob($paid));
        $uniqueLock->release(new UpgradeJob($retryable));
    }

    public function test_one_dispatch_failure_does_not_block_later_rows(): void
    {
        $first = $this->createUpgrade(
            ServiceUpgrade::STATUS_PAID_COMMITTED
        );
        $second = $this->createUpgrade(
            ServiceUpgrade::STATUS_RETRYABLE_FAILED
        );
        $recovery = new class extends ServiceUpgradeDispatchRecoveryService
        {
            /** @var array<int, int> */
            public array $attempted = [];

            protected function dispatchUpgrade(
                ServiceUpgrade $upgrade
            ): bool {
                $this->attempted[] = (int) $upgrade->id;
                if (count($this->attempted) === 1) {
                    throw new RuntimeException('Queue broker unavailable.');
                }

                return true;
            }
        };

        $summary = $recovery->recover();

        $this->assertSame(
            [(int) $first->id, (int) $second->id],
            $recovery->attempted
        );
        $this->assertSame([
            'scanned' => 2,
            'dispatched' => 1,
            'skipped' => 0,
            'failed' => 1,
        ], $summary);
    }

    public function test_upgrade_job_uniqueness_covers_its_retry_window(): void
    {
        $upgrade = new ServiceUpgrade;
        $upgrade->id = 123;
        $upgrade->service_id = 456;
        $job = new UpgradeJob($upgrade);
        $retryWindow = array_sum($job->backoff())
            + ($job->tries * $job->timeout);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('123', $job->uniqueId());
        $this->assertGreaterThan($retryWindow, $job->uniqueFor);
    }

    public function test_existing_unique_job_is_counted_as_skipped(): void
    {
        Queue::fake();
        $upgrade = $this->createUpgrade(
            ServiceUpgrade::STATUS_PAID_COMMITTED
        );
        $job = new UpgradeJob($upgrade);
        $uniqueLock = new UniqueLock(app('cache')->store());
        $this->assertTrue($uniqueLock->acquire($job));

        try {
            $summary = app(
                ServiceUpgradeDispatchRecoveryService::class
            )->recover();

            $this->assertSame([
                'scanned' => 1,
                'dispatched' => 0,
                'skipped' => 1,
                'failed' => 0,
            ], $summary);
            Queue::assertNothingPushed();
        } finally {
            $uniqueLock->release($job);
        }
    }

    public function test_broker_failure_releases_the_unique_dispatch_lock(): void
    {
        $upgrade = $this->createUpgrade(
            ServiceUpgrade::STATUS_PAID_COMMITTED
        );
        Queue::shouldReceive('push')
            ->once()
            ->andThrow(new RuntimeException('Queue broker unavailable.'));

        try {
            app(ServiceUpgradeDispatchRecoveryService::class)
                ->dispatchById((int) $upgrade->id);
            $this->fail('Expected the simulated queue push to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Queue broker unavailable.',
                $exception->getMessage()
            );
        }

        $job = new UpgradeJob($upgrade);
        $uniqueLock = new UniqueLock(app('cache')->store());
        $this->assertTrue(
            $uniqueLock->acquire($job),
            'The recovery pass must be able to reacquire the job lock.'
        );
        $uniqueLock->release($job);
    }

    public function test_queue_visibility_has_a_safe_server_job_margin(): void
    {
        $job = new UpgradeJob(new ServiceUpgrade);

        foreach (['redis', 'database'] as $connection) {
            $retryAfter = (int) config(
                "queue.connections.{$connection}.retry_after"
            );
            $this->assertGreaterThanOrEqual(
                $job->timeout + 60,
                $retryAfter,
                "{$connection} retry_after must exceed the server job timeout."
            );
        }

        $environmentExample = file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression(
            '/^REDIS_QUEUE_RETRY_AFTER=300$/m',
            $environmentExample
        );
        $this->assertMatchesRegularExpression(
            '/^DB_QUEUE_RETRY_AFTER=300$/m',
            $environmentExample
        );
    }

    public function test_recovery_command_is_registered(): void
    {
        Queue::fake();

        $this->artisan('paymenter:recover-service-upgrade-dispatches')
            ->expectsOutput(
                'Scanned 0 upgrade(s): 0 dispatched, 0 skipped, 0 failed.'
            )
            ->assertExitCode(0);

        $schedule = file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString(
            'Schedule::command(RecoverServiceUpgradeDispatches::class)',
            $schedule
        );
    }

    private function createUpgrade(string $status): ServiceUpgrade
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
        ]);

        return ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => $status,
            'type' => 'product',
            'provisioning_started_at' => $status === ServiceUpgrade::STATUS_PROVISIONING
                    ? now()
                    : null,
            'active_service_guard_id' => in_array(
                $status,
                [
                    ServiceUpgrade::STATUS_PAID_COMMITTED,
                    ServiceUpgrade::STATUS_PROVISIONING,
                    ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                    ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                ],
                true
            ) ? $service->id : null,
        ]);
    }
}
