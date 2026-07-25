<?php

namespace App\Http\Requests\Api\Admin\Services;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Services\Service\DurableFulfillmentService;

class UpdateServiceRequest extends AdminApiRequest
{
    protected $permission = 'services.update';

    public function rules(): array
    {
        return [
            'product_id' => [
                'sometimes',
                'required',
                'exists:products,id',
                function ($attribute, $value, $fail): void {
                    $this->validateDynamicQuantity((int) $value, $fail);
                    $service = $this->route('service');
                    if (! $service instanceof Service) {
                        return;
                    }
                    $currentDynamic = $service->product?->usesDynamicResources() ?? false;
                    $targetDynamic = Product::query()
                        ->find((int) $value)
                        ?->usesDynamicResources() ?? false;
                    if (
                        (int) $service->product_id !== (int) $value
                        && (
                            $currentDynamic
                            || $targetDynamic
                            || $this->reservationBacked()
                        )
                    ) {
                        $fail('Converting to or from a dynamic resource product requires the capacity-aware upgrade coordinator.');
                    }
                },
            ],
            'plan_id' => [
                'sometimes',
                'required',
                'exists:plans,id',
                function ($attribute, $value, $fail) {
                    $service = $this->route('service');
                    if (
                        $service instanceof Service
                        && (int) $service->plan_id !== (int) $value
                        && $this->reservationBacked()
                    ) {
                        $fail('Reservation-backed service identity is immutable outside the capacity-aware upgrade coordinator.');
                    }
                    $productId = $this->input('product_id');
                    if ($productId && !Plan::where('id', $value)->where('priceable_type', Product::class)->where('priceable_id', $productId)->exists()) {
                        // Check if the plan belongs to the specified product
                        $fail('The selected plan does not belong to the specified product.');
                    }
                },
            ],
            'user_id' => [
                'sometimes',
                'required',
                'exists:users,id',
                function ($attribute, $value, $fail): void {
                    $service = $this->route('service');
                    if (
                        $service instanceof Service
                        && (int) $service->user_id !== (int) $value
                        && $this->reservationBacked()
                    ) {
                        $fail('Reservation-backed service ownership cannot be changed.');
                    }
                },
            ],
            /**
             * @default 1
             */
            'quantity' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                function ($attribute, $value, $fail): void {
                    $service = $this->route('service');
                    $productId = (int) $this->input(
                        'product_id',
                        $service instanceof Service
                            ? $service->product_id
                            : 0
                    );
                    $this->validateDynamicQuantity($productId, $fail, (int) $value);
                    if (
                        $service instanceof Service
                        && (int) $service->quantity !== (int) $value
                        && $this->reservationBacked()
                    ) {
                        $fail('Reservation-backed service quantity requires the capacity-aware upgrade coordinator.');
                    }
                },
            ],
            /**
             * @default pending
             */
            'status' => [
                'sometimes',
                'required',
                'in:pending,provisioning,provisioning_failed,active,cancellation_pending,cancelled,suspended',
                function ($attribute, $value, $fail): void {
                    $service = $this->route('service');
                    $productId = (int) $this->input(
                        'product_id',
                        $service instanceof Service ? $service->product_id : 0
                    );
                    if (
                        Product::query()->find($productId)?->usesDynamicResources()
                        || $this->reservationBacked()
                    ) {
                        $fail('Dynamic service status is controlled by the fulfillment state machine.');
                    }
                },
            ],
            'expires_at' => 'sometimes|nullable|date|after_or_equal:today',
            /**
             * @example USD
             */
            'currency_code' => [
                'sometimes',
                'required',
                'string',
                'exists:currencies,code',
                function ($attribute, $value, $fail): void {
                    $service = $this->route('service');
                    if (
                        $service instanceof Service
                        && strtoupper((string) $service->currency_code)
                            !== strtoupper((string) $value)
                        && $this->reservationBacked()
                    ) {
                        $fail('Reservation-backed service currency cannot be changed.');
                    }
                },
            ],
            'price' => 'sometimes|required|numeric|min:0',
            'coupon_id' => 'sometimes|nullable|exists:coupons,id',
            'subscription_id' => 'sometimes|nullable|string|max:255',
            'order_id' => 'sometimes|nullable|exists:orders,id',
        ];
    }

    private function validateDynamicQuantity(
        int $productId,
        callable $fail,
        ?int $quantity = null
    ): void {
        $service = $this->route('service');
        $quantity ??= (int) $this->input(
            'quantity',
            $service instanceof Service ? $service->quantity : 1
        );
        if (
            $quantity !== 1
            && Product::query()->find($productId)?->usesDynamicResources()
        ) {
            $fail('Dynamic resource services must have a quantity of one.');
        }
    }

    private function reservationBacked(): bool
    {
        $service = $this->route('service');

        return $service instanceof Service
            && app(DurableFulfillmentService::class)
                ->isReservationBacked($service);
    }
}
