<?php

namespace App\Models;

use App\Models\Concerns\SerializesCapacityConfigurationMutations;
use App\Models\Traits\HasPlans;
use App\Services\Service\CapacityConfigurationMutationGuard;
use App\Services\ServiceUpgrade\CouponUpgradeMutationGuard;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use OwenIt\Auditing\Contracts\Auditable;

class Product extends Model implements Auditable
{
    use HasFactory, HasPlans, SerializesCapacityConfigurationMutations, Traits\Auditable;

    protected $guarded = [];

    protected $auditInclude = [
        'name',
        'description',
        'category_id',
        'enabled',
    ];

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            $guard = app(CapacityConfigurationMutationGuard::class);
            $guard->assertProductStockMutationFresh($product);
            if (
                !$product->exists
                || !$product->isDirty('server_id')
            ) {
                return;
            }

            $guard->assertProductProvisionerActivationSafe($product);
            $guard->assertProductsMutable(
                [(int) $product->id],
                'product provisioning identity',
                destructive: true
            );
        });
        static::deleting(function (Product $product): void {
            app(CouponUpgradeMutationGuard::class)
                ->assertProductDeletionSafe((int) $product->id);
            app(CapacityConfigurationMutationGuard::class)
                ->assertProductsMutable(
                    [(int) $product->id],
                    'product',
                    destructive: true
                );
        });
    }

    /**
     * Get the category of the product.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the configurable options of the product.
     */
    public function configOptions(): HasManyThrough
    {
        return $this->hasManyThrough(ConfigOption::class, ConfigOptionProduct::class, 'product_id', 'id', 'id', 'config_option_id')->where('config_options.hidden', false)->orderBy('config_options.sort', 'asc')->orderBy('config_options.id', 'desc');
    }

    /**
     * Get the extension of the product.
     */
    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Get all services using this product.
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Get the settings of the product.
     */
    public function settings(): MorphMany
    {
        return $this->morphMany(Setting::class, 'settingable');
    }

    /**
     * Get all available products upgrades
     */
    public function upgrades()
    {
        return $this->belongsToMany(
            Product::class,
            'product_upgrades',
            'product_id',
            'upgrade_id'
        )->using(ProductUpgrade::class)->withTimestamps();
    }

    /**
     * Gets all upgradable config options for the product.
     */
    public function upgradableConfigOptions(): HasManyThrough
    {
        return $this->hasManyThrough(ConfigOption::class, ConfigOptionProduct::class, 'product_id', 'id', 'id', 'config_option_id')->where('config_options.hidden', false)->where('config_options.upgradable', true)->orderBy('config_options.sort', 'asc')->orderBy('config_options.id', 'desc');
    }

    public function usesDynamicResources(): bool
    {
        if (
            !$this->exists
            || !$this->server()
                ->where('extension', 'Pterodactyl')
                ->exists()
        ) {
            return false;
        }

        return ConfigOption::query()
            ->whereHas(
                'products',
                fn ($query) => $query->whereKey($this->getKey())
            )
            ->where('type', 'dynamic_slider')
            ->whereNull('parent_id')
            ->where('hidden', false)
            ->get()
            ->contains(fn (ConfigOption $option): bool => in_array(
                strtolower((string) $option->getMetadata('resource_type', '')),
                ['memory', 'cpu', 'disk'],
                true
            ));
    }
}
