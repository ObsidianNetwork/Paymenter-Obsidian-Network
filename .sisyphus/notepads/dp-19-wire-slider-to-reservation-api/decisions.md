## 2026-04-27
- Persist reservation tokens in `cart_items.checkout_config.dp_reservation_token` and mirror them into `sessionStorage` per `(product, plan)` so checkout can confirm holds without coupling core code directly to extension models.
- Keep the core checkout integration loosely coupled with `class_exists()` + container resolution so Paymenter still checks out when the DynamicPterodactyl extension is absent.
- Support guest idempotent reservation creation by reusing the same idempotency key path in `ReservationService`, with guest identity sourced from cart cookie or session storage on the frontend.
