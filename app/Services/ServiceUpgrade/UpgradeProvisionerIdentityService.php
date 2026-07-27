<?php

namespace App\Services\ServiceUpgrade;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Extension;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Support\PanelEndpointIdentity;
use Illuminate\Support\Facades\DB;

/**
 * Pins an upgrade to one exact external provisioner and endpoint.
 */
class UpgradeProvisionerIdentityService
{
    /**
     * @return array<string, mixed>
     */
    public function identity(
        Product $source,
        Product $target,
        ?Service $service = null
    ): array {
        $sourceServerId = $source->server_id === null
            ? null
            : (int) $source->server_id;
        $targetServerId = $target->server_id === null
            ? null
            : (int) $target->server_id;
        if ($sourceServerId !== $targetServerId) {
            throw new DisplayException(
                'Product upgrades cannot move a service between server provisioners. Use an explicit migration workflow.'
            );
        }
        if ($sourceServerId === null) {
            return ['mode' => 'serverless'];
        }

        $query = Extension::withTrashed()
            ->whereKey($sourceServerId);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $extension = $query->first();
        if (
            $extension === null
            || $extension->deleted_at !== null
            || !(bool) $extension->enabled
            || $extension->type !== 'server'
            || trim((string) $extension->extension) === ''
        ) {
            throw new DisplayException(
                'The shared upgrade provisioner is disabled, deleted, or invalid.'
            );
        }

        $settingsQuery = Setting::query()
            ->where('settingable_id', $extension->id)
            ->whereIn('settingable_type', [
                Extension::class,
                Server::class,
            ])
            ->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $settingsQuery->lockForUpdate();
        }
        $settings = $settingsQuery->get();
        $extension->setRelation('settings', $settings);
        try {
            if (!ExtensionHelper::hasFunction(
                $extension,
                'upgradeServer'
            )) {
                throw new DisplayException(
                    'The selected server provisioner does not support upgrades.'
                );
            }
        } catch (DisplayException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new DisplayException(
                'The selected server provisioner cannot be loaded for upgrades.',
                previous: $exception
            );
        }

        $endpoints = $settings
            ->filter(
                fn (Setting $setting): bool => preg_match(
                    '/(^|_)(host|hostname|url|endpoint|domain|ip|port|server)(_|$)/i',
                    (string) $setting->key
                ) === 1
            )
            ->mapWithKeys(fn (Setting $setting): array => [
                strtolower((string) $setting->key) => $this->canonicalEndpointValue(
                    (string) $setting->value
                ),
            ])
            ->sortKeys()
            ->all();

        $identity = [
            'mode' => 'external',
            'server_extension_id' => (int) $extension->id,
            'type' => (string) $extension->type,
            'extension' => (string) $extension->extension,
            'endpoint_fingerprint' => hash(
                'sha256',
                json_encode(
                    $endpoints,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                )
            ),
        ];

        if (strtolower((string) $extension->extension) === 'enhance') {
            if ($service === null) {
                throw new DisplayException(
                    'Enhance upgrades require a service-bound customer identity.'
                );
            }
            $organizationQuery = $service->user
                ->properties()
                ->where('key', 'enhance_orgId')
                ->orderBy('id');
            if (DB::transactionLevel() > 0) {
                $organizationQuery->lockForUpdate();
            }
            $organizations = $organizationQuery->get();
            $organizationId = trim((string) (
                $organizations->first()?->value ?? ''
            ));
            if ($organizations->count() !== 1 || $organizationId === '') {
                throw new DisplayException(
                    'The Enhance customer organization identity is missing or ambiguous.'
                );
            }
            $identity['user_identity'] = [
                'enhance_org_id' => $organizationId,
            ];
        }

        return $identity;
    }

    public function assertCurrent(ServiceUpgrade $upgrade): void
    {
        $upgrade->loadMissing([
            'service.product',
            'product',
        ]);
        $current = $this->identity(
            $upgrade->service->product,
            $upgrade->product,
            $upgrade->service
        );
        $source = data_get(
            $upgrade->source_snapshot,
            'provisioner'
        );
        $target = data_get(
            $upgrade->target_snapshot,
            'provisioner'
        );
        if (
            !is_array($source)
            || !is_array($target)
            || $source !== $target
            || $source !== $current
        ) {
            throw new \RuntimeException(
                'The upgrade provisioner no longer matches its signed identity.'
            );
        }
    }

    private function canonicalEndpointValue(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^https?://#i', $value) === 1) {
            return PanelEndpointIdentity::canonicalUrl($value);
        }

        return strtolower($value);
    }
}
