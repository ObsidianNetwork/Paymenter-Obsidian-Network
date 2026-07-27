<?php

namespace App\Livewire;

use App\Classes\Cart as ClassesCart;
use App\Classes\Price;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Cart as CartModel;
use App\Models\ConfigOption;
use App\Models\Extension;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\CapacityConfigurationLockService;
use App\Services\Service\CapacityServiceCreationCoordinator;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\ServiceJobDispatchService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Throwable;

class Cart extends Component
{
    #[Locked]
    public $total;

    public $gateway;

    public $coupon;

    public $use_credits = true;

    public $tos;

    public function mount()
    {
        if (ClassesCart::get()->coupon_id) {
            $this->coupon = ClassesCart::get()->coupon;
        }
        $this->updateTotal();
    }

    private function updateTotal()
    {
        if (ClassesCart::items()->count() == 0) {
            $this->total = null;

            return;
        }
        $this->total = new Price(['price' => ClassesCart::items()->sum(fn ($item) => $item->price->total * $item->quantity), 'currency' => ClassesCart::get()->currency]);
        $this->gateways = ExtensionHelper::getCheckoutGateways($this->total->total, $this->total->currency->code, 'cart', ClassesCart::items());
        if (count($this->gateways) > 0 && !array_search($this->gateway, array_column($this->gateways, 'id')) !== false) {
            $this->gateway = $this->gateways[0]->id;
        }
    }

    public function applyCoupon()
    {
        if ($this->coupon && ClassesCart::get()->coupon_id) {
            return $this->notify('Coupon code already applied', 'error');
        }

        try {
            $cart = ClassesCart::applyCoupon($this->coupon);
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');
            $this->coupon = null;

            return;
        }
        $this->coupon = $cart->coupon;
        $this->updateTotal();
        $this->notify('Coupon code applied successfully', 'success');
    }

    public function removeCoupon()
    {
        if (!$this->coupon || !ClassesCart::get()->coupon_id) {
            return $this->notify('No coupon code applied', 'error');
        }
        ClassesCart::removeCoupon();
        $this->coupon = null;
        $this->updateTotal();
        $this->notify('Coupon code removed successfully', 'success');
    }

    public function removeProduct($index)
    {
        ClassesCart::remove($index);
        $this->updateTotal();
    }

    public function updateQuantity($index, $quantity)
    {
        ClassesCart::updateQuantity($index, $quantity);
        $this->updateTotal();
    }

    // Checkout
    public function checkout()
    {
        if (ClassesCart::items()->count() === 0) {
            return $this->notify('Your cart is empty', 'error');
        }
        if (!Auth::check()) {
            return redirect()->guest('login');
        }
        if (config('settings.mail_must_verify') && !Auth::user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }
        if (config('settings.tos') && !$this->tos) {
            return $this->notify('You must accept the terms of service', 'error');
        }

        $cartId = ClassesCart::get()->getKey();
        if ($cartId === null) {
            return $this->notify('Your cart is empty', 'error');
        }

        // Start database transaction
        DB::beginTransaction();
        try {
            $user = User::where('id', Auth::id())->lockForUpdate()->first();
            $cart = CartModel::query()
                ->whereKey($cartId)
                ->lockForUpdate()
                ->first();
            if ($cart === null) {
                throw new DisplayException(
                    'This cart was already checked out. Refresh to view the created order.'
                );
            }
            if (
                $cart->user_id !== null
                && (int) $cart->user_id !== (int) $user->id
            ) {
                throw new DisplayException(
                    'This cart belongs to a different customer.'
                );
            }
            if ($cart->user_id === null) {
                $cart->user_id = $user->id;
                $cart->save();
            }
            $cart->load([
                'coupon',
                'items.plan',
                'items.product',
                'items.product.configOptions.children.plans.prices',
            ]);
            if ($cart->items->isEmpty()) {
                throw new DisplayException('Your cart is empty.');
            }

            if (
                $cart->coupon
                && !ClassesCart::validateAndRefreshCoupon($cart, false)
            ) {
                $invalidCouponId = (int) $cart->coupon_id;
                DB::rollBack();
                ClassesCart::removeCoupon($cart, $invalidCouponId);
                $this->coupon = null;
                $this->updateTotal();

                return $this->notify(
                    'This coupon can no longer be used',
                    'error'
                );
            }

            // Lock the orderproducts
            foreach ($cart->items->sortBy([
                ['product_id', 'asc'],
                ['plan_id', 'asc'],
                ['id', 'asc'],
            ]) as $item) {
                // Execute the lock queries and replace stale cart relations
                // before stock, price identity, and capacity are checked.
                $lockedProduct = app(
                    CapacityConfigurationLockService::class
                )->lockProduct((int) $item->product_id);
                $lockedPlan = $lockedProduct->plans->firstWhere(
                    'id',
                    (int) $item->plan_id
                );
                if ($lockedPlan === null) {
                    throw new DisplayException(
                        'The selected product plan is no longer available.'
                    );
                }
                $item->setRelation('product', $lockedProduct);
                $item->setRelation('plan', $lockedPlan);
                if ($item->product->usesDynamicResources() && (int) $item->quantity !== 1) {
                    throw new DisplayException(
                        'Dynamic resource products require a quantity of one.'
                    );
                }
                $this->refreshCapacityReservation($item);

                if ($lockedProduct->per_user_limit > 0) {
                    $existingServiceCount = $user->services()
                        ->where('product_id', $lockedProduct->id)
                        ->count();
                    $cartQuantityForProduct = $cart->items
                        ->where('product_id', $lockedProduct->id)
                        ->sum('quantity');

                    if (
                        $existingServiceCount
                            >= $lockedProduct->per_user_limit
                        || ($cartQuantityForProduct + $existingServiceCount)
                            > $lockedProduct->per_user_limit
                    ) {
                        throw new DisplayException(__('product.user_limit', [
                            'product' => $lockedProduct->name,
                        ]));
                    }
                }
                if ($lockedProduct->stock !== null) {
                    if ($lockedProduct->stock < $item->quantity) {
                        throw new DisplayException(__('product.out_of_stock', [
                            'product' => $lockedProduct->name,
                        ]));
                    }

                    $lockedProduct->stock -= $item->quantity;
                    $lockedProduct->save();
                }
            }
            $requiresPayment = $cart->items->contains(
                fn ($item): bool => ((float) $item->price->total * (int) $item->quantity) > 0
            );
            // Create the order
            $order = new Order([
                'user_id' => $user->id,
                'currency_code' => $cart->currency_code,
            ]);
            $order->save();

            // Create the invoice
            $invoice = null;
            $guaranteedUntil = now()->addDays(7);
            if ($requiresPayment) {
                $invoice = new Invoice([
                    'user_id' => $user->id,
                    'due_at' => $guaranteedUntil,
                    'currency_code' => $cart->currency_code,
                ]);
                $invoice->save();
            }

            // Assemble every paid invoice line before the first capacity
            // reservation makes the invoice immutable. Bindings are applied
            // afterward in service-ID order.
            $capacityBindings = [];

            // Create the services
            foreach ($cart->items as $item) {
                // Is it a lifetime coupon, then we can adjust the price of the service
                if (is_object($cart->coupon) && ($cart->coupon->recurring === null || (int) $cart->coupon->recurring == 1)) {
                    // Apply coupon only to first billing cycle (use original price for recurring)
                    $price = $item->price->original_price;
                } else {
                    // Apply coupon to all billing cycles (use discounted price)
                    $price = $item->price->price;
                }
                // Create the service
                $service = CapacityServiceCreationCoordinator::run(
                    fn () => $order->services()->create([
                        'user_id' => $user->id,
                        'currency_code' => $cart->currency_code,
                        'product_id' => $item->product->id,
                        'plan_id' => $item->plan->id,
                        'price' => $price,
                        // Price::price is recurring-only and already reflects
                        // the current-cycle coupon and exclusive tax. Never
                        // seed refundable period value from total, which also
                        // contains the one-time setup fee.
                        'period_base_price' => $item->price->price,
                        'current_period_price' => $item->price->price,
                        'pricing_ledger_started_at' => now(),
                        'pricing_ledger_verified_at' => $invoice === null ? now() : null,
                        'billing_cycles_completed' => 1,
                        'quantity' => $item->quantity,
                        'coupon_id' => $cart->coupon_id,
                    ])
                );

                foreach ($item->checkout_config as $key => $value) {
                    $service->properties()->updateOrCreate([
                        'key' => $key,
                    ], [
                        'value' => $value,
                    ]);
                }

                foreach ($item->config_options as $configOption) {
                    $configOption = (object) $configOption;
                    // Text, number and dynamic_slider store values as properties (not child option references)
                    if (in_array($configOption->option_type, ['text', 'number', 'dynamic_slider'])) {
                        if (!isset($configOption->value)) {
                            continue;
                        }
                        $service->properties()->updateOrCreate([
                            'key' => $configOption->option_env_variable ? $configOption->option_env_variable : $configOption->option_name,
                        ], [
                            'name' => $configOption->option_name,
                            'value' => $configOption->value,
                        ]);

                        // Dual-write dynamic_slider selections as ServiceConfig rows so
                        // Service::calculatePrice() can recalculate without reading properties.
                        if ($configOption->option_type === 'dynamic_slider') {
                            $service->configs()->updateOrCreate(
                                ['config_option_id' => $configOption->option_id],
                                [
                                    'config_value_id' => null,
                                    'slider_value' => $configOption->value,
                                ]
                            );
                        }

                        continue;
                    }
                    if (!isset($configOption->value) || $configOption->value === null) {
                        continue;
                    }

                    $service->configs()->create([
                        'config_option_id' => $configOption->option_id,
                        'config_value_id' => $configOption->value,
                    ]);
                }

                $reservationService = $this->capacityReservationService($item);

                // The invoice line must exist before the reservation makes this
                // invoice capacity-backed. From that point onward the line is
                // immutable and all fulfillment paths use invoice -> service
                // -> reservation -> item lock order.
                if ($invoice !== null) {
                    $invoice->items()->create([
                        'reference_id' => $service->id,
                        'reference_type' => Service::class,
                        'price' => $item->price->total,
                        'quantity' => $item->quantity,
                        'description' => $service->description,
                    ]);
                }

                if ($reservationService !== null) {
                    if ($invoice !== null) {
                        $capacityBindings[] = [
                            'reservation_service' => $reservationService,
                            'cart_item' => $item,
                            'service' => $service,
                        ];
                    } else {
                        $reservationService->bindCartItemToService(
                            $item,
                            $service,
                            $user,
                            $guaranteedUntil
                        );
                    }
                }

                if ($invoice === null) {
                    if ($reservationService !== null) {
                        $reservationService->commitFreeService($service);
                        app(ServiceJobDispatchService::class)
                            ->requestCreate($service);
                    } else {
                        // Preserve the existing behavior for non-dynamic products.
                        FulfillmentStatusTransitionService::run(
                            $service,
                            function () use ($service): void {
                                $service->status =
                                    Service::STATUS_ACTIVE;
                                $service->expires_at =
                                    $service->calculateNextDueDate();
                                $service->save();
                            }
                        );
                        if ($service->product->server) {
                            app(ServiceJobDispatchService::class)
                                ->requestCreate($service);
                        }
                    }
                }
            }

            usort(
                $capacityBindings,
                fn (array $left, array $right): int => $left['service']->id <=> $right['service']->id
            );
            foreach ($capacityBindings as $binding) {
                $binding['reservation_service']->bindCartItemToService(
                    $binding['cart_item'],
                    $binding['service'],
                    $user,
                    $invoice->due_at,
                    $invoice
                );
            }

            // Consume the cart before releasing the user/cart locks. A second
            // request with the same cookie must observe a missing cart instead
            // of replaying the stale item collection into duplicate services.
            CartModel::query()->whereKey($cart->id)->delete();

            // Commit the transaction
            DB::commit();

            // Clear the cart
            ClassesCart::clear();

            if (!$requiresPayment) {
                // Is it only one item? Then redirect to the service page
                if ($order->services->count() == 1) {
                    return $this->redirect(route('services.show', $order->services->first()), true);
                }

                return $this->redirect(route('services'), true);
            } else {
                return $this->redirect(route('invoices.show', [$invoice, 'pay' => true]), true);
            }
        } catch (Throwable $e) {
            // Rollback the transaction
            DB::rollBack();
            // Return error message
            // Is it a real error or a validation error?
            // If it's a validation error, you can use the $this->addError() method to display the error message to the user.
            if ($e instanceof DisplayException) {
                return $this->notify($e->getMessage(), 'error');
            } else {
                report($e);
                $this->notify('An error occurred while processing your order. Please try again later.');
            }
        }
    }

    public function render()
    {
        return view('cart');
    }

    private function refreshCapacityReservation($item): void
    {
        $reservationService = $this->capacityReservationService($item);
        if ($reservationService !== null) {
            $reservationService->reserveForCartItem($item);
        }
    }

    private function capacityReservationService($item): mixed
    {
        $usesPterodactyl = $item->product->server?->extension === 'Pterodactyl';
        $hasDynamicSlider = $usesPterodactyl && ConfigOption::query()
            ->whereHas('products', fn ($query) => $query->whereKey($item->product_id))
            ->where('type', 'dynamic_slider')
            ->where('hidden', false)
            ->whereNull('parent_id')
            ->get()
            ->contains(fn ($option) => in_array(
                strtolower((string) $option->getMetadata('resource_type', '')),
                ['memory', 'cpu', 'disk'],
                true
            ));

        if (!$hasDynamicSlider) {
            return null;
        }

        $reservationServiceClass = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\ReservationService';
        $reservationExtensionEnabled = Extension::query()
            ->where('extension', 'DynamicPterodactyl')
            ->where('enabled', true)
            ->exists();

        if (!$reservationExtensionEnabled || !class_exists($reservationServiceClass)) {
            throw new DisplayException('Dynamic capacity reservations are unavailable. Please contact support.');
        }

        return app($reservationServiceClass);
    }
}
