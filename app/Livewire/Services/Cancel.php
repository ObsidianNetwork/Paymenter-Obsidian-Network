<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Service;
use App\Services\Service\ServiceCancellationRequestService;
use Livewire\Attributes\Validate;

class Cancel extends Component
{
    public Service $service;

    #[Validate('required|in:end_of_period,immediate')]
    public $type = 'end_of_period';

    #[Validate('required|max:255')]
    public $reason = '';

    public function cancelService()
    {
        $this->authorize('view', $this->service);

        $this->validate();

        // The synchronous event listener owns the invoice and fulfillment
        // transition. Keep the cancellation row in that same transaction so
        // a listener failure cannot leave an orphan request that suppresses
        // provisioning without a durable termination intent.
        app(ServiceCancellationRequestService::class)->create([
            'service_id' => $this->service->id,
            'type' => $this->type,
            'reason' => $this->reason,
        ]);

        $this->notify(__('services.cancellation_requested'), 'success', true);

        $this->redirect(route('services.show', $this->service), true);
    }

    public function render()
    {
        return view('services.cancel')->layoutData([
            'title' => __('services.cancellation', ['service' => $this->service->product->name]),
            'sidebar' => true,
        ]);
    }
}
