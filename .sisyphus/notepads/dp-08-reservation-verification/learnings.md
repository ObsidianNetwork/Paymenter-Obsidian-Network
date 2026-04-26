## 2026-04-23

- DynamicPterodactyl extension tests run against MariaDB `paymenter_test`, not SQLite; new extension migrations must be applied to that DB before phpunit will pass when tests hit real tables.
- Avoid `ConfigOption` Eloquent static helpers in this test suite after `PricingCalculatorServiceTest` overloads the model with Mockery; direct `DB::table('config_options')` inserts/queries are safer for request/feature tests.
- `ReservationService::presentReservation()` is the right normalization point for idempotent replay responses; using it for fresh creates too keeps the API shape stable.
