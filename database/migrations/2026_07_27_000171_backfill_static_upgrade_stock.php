<?php

use App\Models\ServiceUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STOCK_OWNING_STATUSES = [
        'awaiting_payment',
        'paid_committed',
        'provisioning',
        'retryable_failed',
        'needs_attention',
    ];

    /**
     * This migration contains no DDL. Every validation and stock mutation is
     * therefore atomic and safely rerunnable on SQLite and MariaDB.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $dynamicProductIds = $this->dynamicProductIds();
            $dynamicUpgradeIds = $this->dynamicUpgradeIds(
                $dynamicProductIds
            );

            if ($dynamicUpgradeIds->isNotEmpty()) {
                DB::table('service_upgrades')
                    ->whereIn('id', $dynamicUpgradeIds->all())
                    ->whereNull('capacity_mode')
                    ->update(['capacity_mode' => 'dynamic']);
            }
            DB::table('service_upgrades')
                ->whereNull('capacity_mode')
                ->update(['capacity_mode' => 'static']);

            $this->assertActiveStaticUpgradeBindings(
                $dynamicProductIds,
                $dynamicUpgradeIds
            );
            $commitments = $this->staticCommitments(
                $dynamicProductIds,
                lockForUpdate: true
            );
            $invalidQuantity = $commitments->first(
                fn ($row): bool => (int) $row->quantity !== 1
            );
            if ($invalidQuantity !== null) {
                throw new RuntimeException(
                    "Service upgrade {$invalidQuantity->upgrade_id} belongs to a quantity-{$invalidQuantity->quantity} service. Reconcile it to quantity one before migrating."
                );
            }
            foreach ($commitments as $commitment) {
                $upgrade = ServiceUpgrade::query()
                    ->find((int) $commitment->upgrade_id);
                if (
                    $upgrade === null
                    || !$upgrade->snapshotFingerprintsAreAuthentic()
                ) {
                    throw new RuntimeException(
                        "Service upgrade {$commitment->upgrade_id} has no authentic signed quote. Reconcile it before reserving target stock."
                    );
                }
                $this->assertExistingStockEvidenceIsCoherent(
                    $commitment
                );
            }

            foreach (
                $commitments->groupBy('product_id')->sortKeys() as $productId => $rows
            ) {
                $product = DB::table('products')
                    ->where('id', $productId)
                    ->lockForUpdate()
                    ->first(['id', 'stock']);
                if ($product === null) {
                    throw new RuntimeException(
                        "Upgrade target product {$productId} disappeared during migration."
                    );
                }

                $unreserved = $rows->filter(
                    fn ($row): bool => $row->target_stock_reserved_at === null
                );
                $finite = $product->stock !== null;
                $required = $finite ? $unreserved->count() : 0;
                if ($finite && (int) $product->stock < $required) {
                    throw new RuntimeException(
                        "Product {$productId} has {$product->stock} stock but outstanding upgrades require {$required}. Reconcile those upgrades before migrating."
                    );
                }

                if ($finite && $required > 0) {
                    DB::table('products')
                        ->where('id', $productId)
                        ->update([
                            'stock' => (int) $product->stock - $required,
                            'updated_at' => now(),
                        ]);
                }

                foreach ($unreserved as $row) {
                    $reservedQuantity = $finite ? 1 : 0;
                    DB::table('service_upgrades')
                        ->where('id', $row->upgrade_id)
                        ->whereNull('target_stock_reserved_at')
                        ->update([
                            'capacity_mode' => 'static',
                            'target_stock_reserved_quantity' => $reservedQuantity,
                            'target_stock_reserved_at' => now(),
                            'target_stock_fingerprint' => $this->stockFingerprint(
                                $row,
                                $reservedQuantity
                            ),
                            'updated_at' => now(),
                        ]);
                }

                foreach (
                    $rows->filter(
                        fn ($row): bool => $row->target_stock_reserved_at !== null
                    ) as $row
                ) {
                    $expectedQuantity = $finite ? 1 : 0;
                    if (
                        (int) $row->target_stock_reserved_quantity
                            !== $expectedQuantity
                    ) {
                        throw new RuntimeException(
                            "Existing stock hold for upgrade {$row->upgrade_id} does not match the target product's finite or unlimited stock mode."
                        );
                    }
                }
            }
        }, 5);
    }

    private function assertActiveStaticUpgradeBindings(
        Collection $dynamicProductIds,
        Collection $dynamicUpgradeIds
    ): void {
        $rows = DB::table('service_upgrades as upgrade')
            ->join(
                'services as service',
                'service.id',
                '=',
                'upgrade.service_id'
            )
            ->whereIn(
                'upgrade.status',
                self::STOCK_OWNING_STATUSES
            )
            ->whereNull('upgrade.legacy_refund_only_at')
            ->where('upgrade.capacity_mode', 'static')
            ->orderBy('upgrade.id')
            ->lockForUpdate()
            ->get([
                'upgrade.id as upgrade_id',
                'upgrade.service_id',
                'upgrade.product_id',
                'upgrade.plan_id',
                'upgrade.currency_code',
                'upgrade.quoted_amount',
                'upgrade.credit_amount',
                'upgrade.active_service_guard_id',
                'upgrade.target_stock_reserved_quantity',
                'upgrade.target_stock_reserved_at',
                'upgrade.target_stock_released_at',
                'upgrade.target_stock_consumed_at',
                'upgrade.target_stock_fingerprint',
                'service.user_id as service_user_id',
                'service.product_id as source_product_id',
                'service.plan_id as source_plan_id',
                'service.quantity as service_quantity',
                'service.currency_code as service_currency_code',
            ]);

        foreach ($rows as $row) {
            if ((int) $row->service_quantity !== 1) {
                throw new RuntimeException(
                    "Service upgrade {$row->upgrade_id} belongs to a quantity-{$row->service_quantity} service. Reconcile it to quantity one before migrating."
                );
            }
            if (
                $dynamicUpgradeIds->contains(
                    (int) $row->upgrade_id
                )
                || $dynamicProductIds->contains(
                    (int) $row->source_product_id
                )
                || $dynamicProductIds->contains(
                    (int) $row->product_id
                )
            ) {
                throw new RuntimeException(
                    "Active static upgrade {$row->upgrade_id} conflicts with dynamic capacity ownership or product metadata."
                );
            }

            $upgrade = ServiceUpgrade::query()
                ->find((int) $row->upgrade_id);
            if (
                $upgrade === null
                || !$upgrade->snapshotFingerprintsAreAuthentic()
            ) {
                throw new RuntimeException(
                    "Service upgrade {$row->upgrade_id} has no authentic signed quote. Reconcile it before reserving target stock."
                );
            }
            $source = $upgrade->source_snapshot;
            $target = $upgrade->target_snapshot;
            $sourceProvisioner = is_array($source)
                ? ($source['provisioner'] ?? null)
                : null;
            $targetProvisioner = is_array($target)
                ? ($target['provisioner'] ?? null)
                : null;
            $valid = is_array($source)
                && is_array($target)
                && (int) ($source['service_id'] ?? 0)
                    === (int) $row->service_id
                && (int) ($target['service_id'] ?? 0)
                    === (int) $row->service_id
                && (int) ($source['user_id'] ?? 0)
                    === (int) $row->service_user_id
                && (int) ($target['user_id'] ?? 0)
                    === (int) $row->service_user_id
                && (int) ($source['product_id'] ?? 0)
                    === (int) $row->source_product_id
                && (int) ($source['plan_id'] ?? 0)
                    === (int) $row->source_plan_id
                && (int) ($target['product_id'] ?? 0)
                    === (int) $row->product_id
                && (int) ($target['plan_id'] ?? 0)
                    === (int) $row->plan_id
                && (int) ($source['quantity'] ?? 0) === 1
                && (int) ($target['quantity'] ?? 0) === 1
                && (int) $row->service_quantity === 1
                && strtoupper(
                    (string) ($source['currency_code'] ?? '')
                ) === strtoupper(
                    (string) $row->service_currency_code
                )
                && strtoupper(
                    (string) ($target['currency_code'] ?? '')
                ) === strtoupper(
                    (string) $row->service_currency_code
                )
                && strtoupper((string) $row->currency_code)
                    === strtoupper(
                        (string) $row->service_currency_code
                    )
                && is_string($source['plan_type'] ?? null)
                && $source['plan_type'] !== ''
                && ($source['plan_type'] ?? null)
                    === ($target['plan_type'] ?? null)
                && is_array($sourceProvisioner)
                && $sourceProvisioner === $targetProvisioner
                && in_array(
                    $sourceProvisioner['mode'] ?? null,
                    ['external', 'serverless'],
                    true
                )
                && is_string($target['upgrade_price'] ?? null)
                && is_string($target['credit_amount'] ?? null)
                && (int) round(
                    (float) $row->quoted_amount * 100
                ) === (int) round(
                    (float) $target['upgrade_price'] * 100
                )
                && (int) round(
                    (float) $row->credit_amount * 100
                ) === (int) round(
                    (float) $target['credit_amount'] * 100
                )
                && (int) ($row->active_service_guard_id ?? 0)
                    === (int) $row->service_id;
            if (!$valid) {
                throw new RuntimeException(
                    "Active static upgrade {$row->upgrade_id} no longer matches its signed source, target, billing, or lifecycle identity."
                );
            }

            if (
                (int) $row->source_product_id
                    === (int) $row->product_id
                && $this->hasStockEvidence($row)
            ) {
                throw new RuntimeException(
                    "Same-product static upgrade {$row->upgrade_id} has impossible target-stock evidence."
                );
            }
        }
    }

    private function hasStockEvidence(object $upgrade): bool
    {
        return $upgrade->target_stock_reserved_quantity !== null
            || $upgrade->target_stock_reserved_at !== null
            || $upgrade->target_stock_released_at !== null
            || $upgrade->target_stock_consumed_at !== null
            || $upgrade->target_stock_fingerprint !== null;
    }

    public function down(): void
    {
        $stockEvidence = DB::table('service_upgrades')
            ->where(function ($query): void {
                $query->whereNotNull('target_stock_reserved_quantity')
                    ->orWhereNotNull('target_stock_reserved_at')
                    ->orWhereNotNull('target_stock_released_at')
                    ->orWhereNotNull('target_stock_consumed_at')
                    ->orWhereNotNull('target_stock_fingerprint');
            })
            ->orderBy('id')
            ->value('id');
        if ($stockEvidence !== null) {
            throw new RuntimeException(
                "Cannot roll back the static-stock backfill because upgrade {$stockEvidence} has durable reservation, release, or consumption evidence."
            );
        }
    }

    private function staticCommitments(
        Collection $dynamicProductIds,
        bool $lockForUpdate = false
    ): Collection {
        $query = DB::table('service_upgrades as upgrade')
            ->join(
                'services as service',
                'service.id',
                '=',
                'upgrade.service_id'
            )
            ->join(
                'products as product',
                'product.id',
                '=',
                'upgrade.product_id'
            )
            ->whereIn(
                'upgrade.status',
                self::STOCK_OWNING_STATUSES
            )
            ->whereNull('upgrade.legacy_refund_only_at')
            ->whereColumn(
                'upgrade.product_id',
                '!=',
                'service.product_id'
            )
            ->where('upgrade.capacity_mode', 'static')
            ->orderBy('upgrade.product_id')
            ->orderBy('upgrade.id');
        if ($dynamicProductIds->isNotEmpty()) {
            $query
                ->whereNotIn(
                    'upgrade.product_id',
                    $dynamicProductIds->all()
                )
                ->whereNotIn(
                    'service.product_id',
                    $dynamicProductIds->all()
                );
        }

        return $query
            ->when(
                $lockForUpdate,
                fn ($builder) => $builder->lockForUpdate()
            )
            ->get([
                'upgrade.id as upgrade_id',
                'upgrade.product_id',
                'upgrade.plan_id',
                'upgrade.service_id',
                'upgrade.currency_code',
                'upgrade.source_fingerprint',
                'upgrade.target_fingerprint',
                'upgrade.capacity_mode',
                'upgrade.target_stock_reserved_quantity',
                'upgrade.target_stock_reserved_at',
                'upgrade.target_stock_released_at',
                'upgrade.target_stock_consumed_at',
                'upgrade.target_stock_fingerprint',
                'service.quantity',
                'product.stock',
            ]);
    }

    private function assertExistingStockEvidenceIsCoherent(
        object $upgrade
    ): void {
        if ($upgrade->target_stock_reserved_at === null) {
            if (
                $upgrade->target_stock_reserved_quantity !== null
                || $upgrade->target_stock_released_at !== null
                || $upgrade->target_stock_consumed_at !== null
                || $upgrade->target_stock_fingerprint !== null
            ) {
                throw new RuntimeException(
                    "Existing stock evidence for upgrade {$upgrade->upgrade_id} is partial and cannot be backfilled safely."
                );
            }

            return;
        }

        $quantity = $upgrade->target_stock_reserved_quantity;
        if (
            $upgrade->capacity_mode !== 'static'
            || $quantity === null
            || !in_array((int) $quantity, [0, 1], true)
            || $upgrade->target_stock_released_at !== null
            || $upgrade->target_stock_consumed_at !== null
            || !is_string($upgrade->target_stock_fingerprint)
            || !hash_equals(
                $upgrade->target_stock_fingerprint,
                $this->stockFingerprint(
                    $upgrade,
                    (int) $quantity
                )
            )
        ) {
            throw new RuntimeException(
                "Existing stock hold for upgrade {$upgrade->upgrade_id} has no valid active ownership proof."
            );
        }
    }

    private function dynamicUpgradeIds(
        Collection $dynamicProductIds
    ): Collection {
        $ids = collect();
        if ($dynamicProductIds->isNotEmpty()) {
            $ids = DB::table('service_upgrades as upgrade')
                ->join(
                    'services as service',
                    'service.id',
                    '=',
                    'upgrade.service_id'
                )
                ->where(function ($query) use (
                    $dynamicProductIds
                ): void {
                    $query->whereIn(
                        'upgrade.product_id',
                        $dynamicProductIds->all()
                    )->orWhereIn(
                        'service.product_id',
                        $dynamicProductIds->all()
                    );
                })
                ->pluck('upgrade.id');
        }

        if (
            Schema::hasTable('ptero_resource_reservations')
            && Schema::hasColumns(
                'ptero_resource_reservations',
                ['purpose', 'service_upgrade_id']
            )
        ) {
            $ids = $ids->merge(
                DB::table('ptero_resource_reservations')
                    ->where('purpose', 'upgrade')
                    ->whereNotNull('service_upgrade_id')
                    ->pluck('service_upgrade_id')
            );
        }

        return $ids
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    private function dynamicProductIds(): Collection
    {
        if (
            !Schema::hasTable('config_options')
            || !Schema::hasTable('config_option_products')
            || !Schema::hasTable('products')
            || !Schema::hasTable('extensions')
        ) {
            return collect();
        }

        return DB::table('config_options')
            ->join(
                'config_option_products',
                'config_option_products.config_option_id',
                '=',
                'config_options.id'
            )
            ->join(
                'products',
                'products.id',
                '=',
                'config_option_products.product_id'
            )
            ->join(
                'extensions as server_extensions',
                'server_extensions.id',
                '=',
                'products.server_id'
            )
            ->where('config_options.type', 'dynamic_slider')
            ->whereNull('config_options.parent_id')
            ->where('config_options.hidden', false)
            ->where('server_extensions.type', 'server')
            ->where('server_extensions.extension', 'Pterodactyl')
            ->whereNull('server_extensions.deleted_at')
            ->get([
                'config_option_products.product_id',
                'config_options.env_variable',
                'config_options.metadata',
            ])
            ->filter(function ($option): bool {
                $metadata = is_string($option->metadata)
                    ? json_decode($option->metadata, true)
                    : (array) $option->metadata;
                $resource = strtolower(
                    (string) ($metadata['resource_type'] ?? '')
                );

                return in_array(
                    $resource,
                    ['memory', 'cpu', 'disk'],
                    true
                );
            })
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
    }

    private function stockFingerprint(
        object $upgrade,
        int $reservedQuantity
    ): string {
        return hash('sha256', json_encode([
            'capacity_mode' => 'static',
            'currency_code' => strtoupper(
                (string) $upgrade->currency_code
            ),
            'reserved_quantity' => $reservedQuantity,
            'service_id' => (int) $upgrade->service_id,
            'source_fingerprint' => (string) $upgrade->source_fingerprint,
            'target_fingerprint' => (string) $upgrade->target_fingerprint,
            'target_plan_id' => (int) $upgrade->plan_id,
            'target_product_id' => (int) $upgrade->product_id,
            'target_quantity' => 1,
            'upgrade_id' => (int) $upgrade->upgrade_id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
};
