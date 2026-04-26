## 2026-04-27
- Dynamic slider reservation coordination lives best at the product-form wrapper level because multiple `dynamic_slider` inputs share one capacity hold.
- `location_id` is not exposed in the customer checkout UI for Pterodactyl products; the current fallback is the first configured `location_ids` product setting.
- Extension API tests require both core test-database migrations and extension migrations to be present before the standalone phpunit config is reliable.
