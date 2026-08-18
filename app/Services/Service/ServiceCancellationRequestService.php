<?php

namespace App\Services\Service;

use App\Models\ServiceCancellation;
use Illuminate\Support\Facades\DB;

class ServiceCancellationRequestService
{
    /**
     * Persist the request and complete its synchronous lifecycle coordinator
     * atomically. In particular, an immediate cancellation must not survive
     * when its invoice or fulfillment transition rolls back.
     *
     * @param  array{service_id: int, type: string, reason?: string|null}  $data
     */
    public function create(array $data): ServiceCancellation
    {
        return DB::transaction(
            fn (): ServiceCancellation => ServiceCancellation::create($data),
            5
        );
    }
}
