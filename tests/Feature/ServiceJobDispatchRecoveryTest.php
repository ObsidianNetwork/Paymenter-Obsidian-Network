<?php

namespace Tests\Feature;

use App\Jobs\Server\CreateJob;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Service;
use App\Models\ServiceJobDispatch;
use App\Models\User;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\ServiceJobDispatchService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ServiceJobDispatchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_transition_and_dispatch_intent_roll_back_together(): void
    {
        Queue::fake();
        $service = $this->service(Service::STATUS_ACTIVE);

        try {
            DB::transaction(function () use ($service): void {
                FulfillmentStatusTransitionService::run(
                    $service,
                    function () use ($service): void {
                        $service->status = Service::STATUS_SUSPENDED;
                        $service->save();
                    }
                );
                app(ServiceJobDispatchService::class)
                    ->requestSuspend($service);

                throw new RuntimeException('force lifecycle rollback');
            });
            $this->fail('The forced lifecycle rollback committed.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'force lifecycle rollback',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
        $this->assertDatabaseCount('service_job_dispatches', 0);
        Queue::assertNothingPushed();
    }

    public function test_broker_failure_is_recovered_from_the_durable_intent(): void
    {
        $service = $this->service(Service::STATUS_ACTIVE);
        Queue::shouldReceive('push')
            ->once()
            ->andThrow(new RuntimeException('Queue broker unavailable.'));

        $dispatch = app(ServiceJobDispatchService::class)
            ->requestCreate($service);

        $dispatch->refresh();
        $this->assertSame(
            'Queue broker unavailable.',
            $dispatch->last_error
        );
        $this->assertTrue($dispatch->available_at->isFuture());

        // A failed Queue::push must not strand Laravel's unique-job lock.
        $job = new CreateJob(
            $service,
            true,
            (int) $dispatch->id,
            (string) $dispatch->dispatch_token
        );
        $uniqueLock = new UniqueLock(app('cache')->store());
        $this->assertTrue($uniqueLock->acquire($job));
        $uniqueLock->release($job);

        $this->travel(2)->minutes();
        Queue::fake();
        $summary = app(ServiceJobDispatchService::class)->recover();

        $this->assertSame([
            'scanned' => 1,
            'dispatched' => 1,
            'skipped' => 0,
            'failed' => 0,
        ], $summary);
        Queue::assertPushed(CreateJob::class, 1);

        $pushed = Queue::pushed(CreateJob::class)->first();
        $uniqueLock->release($pushed);
    }

    public function test_renewal_supersedes_a_queued_suspend(): void
    {
        Queue::fake();
        $service = $this->service(Service::STATUS_SUSPENDED);
        $dispatches = app(ServiceJobDispatchService::class);
        $dispatches->requestSuspend($service);
        $staleSuspend = Queue::pushed(SuspendJob::class)->first();

        FulfillmentStatusTransitionService::run(
            $service,
            function () use ($service): void {
                $service->status = Service::STATUS_ACTIVE;
                $service->save();
            }
        );
        $dispatches->requestUnsuspend($service);
        $currentUnsuspend = Queue::pushed(UnsuspendJob::class)->first();

        $staleSuspend->handle($dispatches);

        $intent = ServiceJobDispatch::query()
            ->where('service_id', $service->id)
            ->firstOrFail();
        $this->assertSame(
            ServiceJobDispatchService::ACTION_UNSUSPEND,
            $intent->action
        );
        $this->assertSame(
            $currentUnsuspend->dispatchToken,
            $intent->dispatch_token
        );

        $uniqueLock = new UniqueLock(app('cache')->store());
        $uniqueLock->release($staleSuspend);
        $uniqueLock->release($currentUnsuspend);
    }

    public function test_cancellation_supersedes_a_queued_create(): void
    {
        Queue::fake();
        $service = $this->service(Service::STATUS_ACTIVE);
        $dispatches = app(ServiceJobDispatchService::class);
        $dispatches->requestCreate($service);
        $staleCreate = Queue::pushed(CreateJob::class)->first();

        FulfillmentStatusTransitionService::run(
            $service,
            function () use ($service): void {
                $service->status = Service::STATUS_CANCELLED;
                $service->save();
            }
        );
        $dispatches->requestTerminate($service);
        $currentTerminate = Queue::pushed(TerminateJob::class)->first();

        $staleCreate->handle($dispatches);

        $intent = ServiceJobDispatch::query()
            ->where('service_id', $service->id)
            ->firstOrFail();
        $this->assertSame(
            ServiceJobDispatchService::ACTION_TERMINATE,
            $intent->action
        );
        $this->assertSame(
            $currentTerminate->dispatchToken,
            $intent->dispatch_token
        );

        $uniqueLock = new UniqueLock(app('cache')->store());
        $uniqueLock->release($staleCreate);
        $uniqueLock->release($currentTerminate);
    }

    public function test_suspend_cannot_supersede_an_unresolved_create(): void
    {
        Queue::fake();
        $service = $this->service(Service::STATUS_ACTIVE);
        $dispatches = app(ServiceJobDispatchService::class);
        $create = $dispatches->requestCreate($service);
        $createJob = Queue::pushed(CreateJob::class)->first();

        try {
            DB::transaction(function () use (
                $dispatches,
                $service
            ): void {
                FulfillmentStatusTransitionService::run(
                    $service,
                    function () use ($service): void {
                        $service->status = Service::STATUS_SUSPENDED;
                        $service->save();
                    }
                );
                $dispatches->requestSuspend($service);
            });
            $this->fail(
                'Suspension replaced an unresolved create intent.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Cannot queue server suspend while server creation is still pending.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
        $intent = ServiceJobDispatch::query()
            ->where('service_id', $service->id)
            ->firstOrFail();
        $this->assertSame(
            ServiceJobDispatchService::ACTION_CREATE,
            $intent->action
        );
        $this->assertSame(
            $create->dispatch_token,
            $intent->dispatch_token
        );
        Queue::assertNotPushed(SuspendJob::class);

        (new UniqueLock(app('cache')->store()))->release($createJob);
    }

    public function test_legacy_create_job_cannot_consume_a_newer_termination_intent(): void
    {
        Queue::fake();
        $service = $this->service(Service::STATUS_ACTIVE);
        $legacyCreate = new CreateJob($service, false);
        $dispatches = app(ServiceJobDispatchService::class);
        $dispatches->requestCreate($service, false);

        // A legacy extension may already have serialized a tokenless create
        // job. The immediate cancellation replaces the durable create intent
        // with terminate before that old job reaches a worker.
        $service->cancellation()->create([
            'type' => 'immediate',
            'reason' => 'Compatibility race regression',
        ]);
        $terminate = ServiceJobDispatch::query()
            ->where('service_id', $service->id)
            ->firstOrFail();
        $this->assertSame(
            ServiceJobDispatchService::ACTION_TERMINATE,
            $terminate->action
        );

        $legacyCreate->handle($dispatches);

        $current = ServiceJobDispatch::query()
            ->where('service_id', $service->id)
            ->firstOrFail();
        $this->assertSame(
            ServiceJobDispatchService::ACTION_TERMINATE,
            $current->action
        );
        $this->assertSame(
            $terminate->dispatch_token,
            $current->dispatch_token
        );

        $uniqueLock = new UniqueLock(app('cache')->store());
        foreach ([CreateJob::class, TerminateJob::class] as $jobClass) {
            foreach (Queue::pushed($jobClass) as $job) {
                $uniqueLock->release($job);
            }
        }
    }

    public function test_stale_dispatch_cleanup_cannot_delete_a_replacement_generation(): void
    {
        Queue::fake();
        $service = $this->service(Service::STATUS_ACTIVE);
        $dispatches = app(ServiceJobDispatchService::class);
        $stale = $dispatches->requestCreate($service, false);
        $replacementToken = (string) Str::uuid();

        DB::table('services')
            ->where('id', $service->id)
            ->update(['status' => Service::STATUS_CANCELLED]);
        $swapped = false;
        DB::listen(function ($query) use (
            &$swapped,
            $replacementToken,
            $stale
        ): void {
            $sql = strtolower(str_replace(
                ['`', '"'],
                '',
                (string) $query->sql
            ));
            if ($swapped || !str_contains($sql, 'from services')) {
                return;
            }

            $swapped = true;
            DB::table('service_job_dispatches')
                ->where('id', $stale->id)
                ->update([
                    'action' => ServiceJobDispatchService::ACTION_TERMINATE,
                    'expected_status' => Service::STATUS_CANCELLED,
                    'dispatch_token' => $replacementToken,
                    'updated_at' => now(),
                ]);
        });

        $this->assertFalse($dispatches->dispatchById(
            (int) $stale->id,
            (string) $stale->dispatch_token
        ));

        $this->assertTrue($swapped);
        $replacement = ServiceJobDispatch::query()
            ->whereKey($stale->id)
            ->firstOrFail();
        $this->assertSame(
            ServiceJobDispatchService::ACTION_TERMINATE,
            $replacement->action
        );
        $this->assertSame(
            $replacementToken,
            $replacement->dispatch_token
        );

        $uniqueLock = new UniqueLock(app('cache')->store());
        foreach (Queue::pushed(CreateJob::class) as $job) {
            $uniqueLock->release($job);
        }
    }

    public function test_all_server_lifecycle_jobs_are_unique(): void
    {
        $service = new Service;
        $service->id = 321;
        $jobs = [
            new CreateJob($service, true, 1, 'create-token'),
            new SuspendJob($service, true, 2, 'suspend-token'),
            new UnsuspendJob($service, 3, 'unsuspend-token'),
            new TerminateJob($service, true, 4, 'terminate-token'),
        ];

        foreach ($jobs as $job) {
            $this->assertInstanceOf(ShouldBeUnique::class, $job);
            $this->assertStringContainsString(
                (string) $job->dispatchToken,
                $job->uniqueId()
            );
            $this->assertNotEmpty($job->middleware());
        }
    }

    public function test_recovery_command_is_registered(): void
    {
        Queue::fake();

        $this->artisan('paymenter:recover-service-job-dispatches')
            ->expectsOutput(
                'Scanned 0 service dispatch(es): 0 dispatched, 0 skipped, 0 failed.'
            )
            ->assertExitCode(0);

        $schedule = file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString(
            'Schedule::command(RecoverServiceJobDispatches::class)',
            $schedule
        );
    }

    private function service(string $status): Service
    {
        $fixture = $this->createProduct();

        return Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => $status,
            'currency_code' => 'USD',
            'price' => '10.00',
        ]);
    }
}
