<?php

namespace Tests\Unit\Helpers;

use App\Helpers\ExtensionHelper;
use App\Models\ConfigOption;
use App\Models\Property;
use App\Models\Service;
use App\Models\ServiceConfig;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

class ExtensionHelperServicePropertiesTest extends TestCase
{
    private function makeService(array $properties = [], array $configs = []): Service
    {
        $service = new Service;
        $service->setAttribute('id', 1);
        $service->setRelation('properties', new EloquentCollection($properties));
        $service->setRelation('configs', new EloquentCollection($configs));

        return $service;
    }

    private function makeProperty(string $key, string|int $value): Property
    {
        $property = new Property;
        $property->setAttribute('key', $key);
        $property->setAttribute('value', (string) $value);

        return $property;
    }

    private function makeConfig(?ConfigOption $option, ?ConfigOption $value = null, ?int $configOptionId = null): ServiceConfig
    {
        $config = new ServiceConfig;
        $config->setAttribute('config_option_id', $configOptionId);
        $config->setRelation('configOption', $option);
        $config->setRelation('configValue', $value);

        return $config;
    }

    public function test_slider_config_without_config_value_returns_properties(): void
    {
        $option = new ConfigOption;
        $option->setAttribute('env_variable', 'RAM');
        $option->setAttribute('type', 'dynamic_slider');

        $service = $this->makeService(
            [
                $this->makeProperty('RAM', '4096'),
            ],
            [
                $this->makeConfig($option, null, 1),
            ],
        );

        $this->assertSame([
            'RAM' => '4096',
        ], ExtensionHelper::getServiceProperties($service));
    }

    public function test_select_config_with_config_value_still_works(): void
    {
        $option = new ConfigOption;
        $option->setAttribute('env_variable', 'STARTER_OPTION');
        $option->setAttribute('type', 'select');

        $value = new ConfigOption;
        $value->setAttribute('env_variable', 'STARTER');

        $service = $this->makeService(
            [],
            [
                $this->makeConfig($option, $value, 2),
            ],
        );

        $this->assertSame([
            'STARTER_OPTION' => 'STARTER',
        ], ExtensionHelper::getServiceProperties($service));
    }

    public function test_orphaned_service_config_row_is_skipped(): void
    {
        $service = $this->makeService(
            [
                $this->makeProperty('RAM', '4096'),
            ],
            [
                $this->makeConfig(null, null, 999),
            ],
        );

        $this->assertSame([
            'RAM' => '4096',
        ], ExtensionHelper::getServiceProperties($service));
    }
}
