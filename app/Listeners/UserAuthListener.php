<?php

namespace App\Listeners;

use App\Classes\Cart;
use App\Events\Auth\Login;
use App\Models\Extension;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

class UserAuthListener
{
    /**
     * Handle the event.
     */
    public function handle(Login|Logout $event): void
    {
        if ($event instanceof Login) {
            // Does request have cart?
            if (Cookie::has('cart')) {
                // Merge cart with user
                $cart = Cart::getOnce();
                if ($cart->exists) {
                    DB::transaction(function () use ($cart, $event) {
                        $cart->user_id = $event->user->id;
                        $cart->save();

                        $reservationServiceClass = '\\Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\ReservationService';
                        $reservationExtensionEnabled = Extension::query()
                            ->where('extension', 'DynamicPterodactyl')
                            ->where('enabled', true)
                            ->exists();

                        if ($reservationExtensionEnabled && class_exists($reservationServiceClass)) {
                            app($reservationServiceClass)->transferCartOwnership(
                                $cart->id,
                                $event->user->id
                            );
                        }
                    });
                }
            } elseif ($event->user->cart) {
                // Set cart to user
                Cookie::queue('cart', $event->user->cart->ulid, 60 * 24 * 30); // 30 days
            }
        } elseif ($event instanceof Logout) {
            // Does request have cart?
            if (Cookie::has('cart')) {
                // Remove cart from user
                Cookie::queue(Cookie::forget('cart'));
            }
        }
    }
}
