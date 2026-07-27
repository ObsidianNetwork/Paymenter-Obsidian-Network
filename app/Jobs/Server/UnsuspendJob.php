<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Services\Service\ServiceJobDispatchService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class UnsuspendJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    public $uniqueFor = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Service $service,
        public ?int $dispatchId = null,
        public ?string $dispatchToken = null
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [
            'unsuspend',
            $this->service->id,
            $this->dispatchToken ?? 'legacy',
        ]);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'server-fulfillment:' . $this->service->id
            ))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(ServiceJobDispatchService $dispatches): void
    {
        $dispatches->run(
            $this->service,
            ServiceJobDispatchService::ACTION_UNSUSPEND,
            $this->dispatchId,
            $this->dispatchToken,
            function (Service $service): void {
                $this->service = $service;
                try {
                    ExtensionHelper::unsuspendServer($this->service);
                } catch (Exception $e) {
                    if ($e->getMessage() !== 'No server assigned to this product') {
                        throw $e;
                    }
                }
            }
        );
    }

    public function failed(Throwable $exception): void
    {
        app(ServiceJobDispatchService::class)->postponeFailure(
            $this->dispatchId,
            $this->dispatchToken,
            $exception
        );
    }
}
