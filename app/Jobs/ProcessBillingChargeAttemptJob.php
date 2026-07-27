<?php

namespace App\Jobs;

use App\Services\Invoice\BillingChargeAttemptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessBillingChargeAttemptJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    public $uniqueFor = 900;

    public function __construct(public int $attemptId) {}

    public function uniqueId(): string
    {
        return "billing-charge-attempt:{$this->attemptId}";
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function handle(BillingChargeAttemptService $attempts): void
    {
        $attempts->process($this->attemptId);
    }
}
